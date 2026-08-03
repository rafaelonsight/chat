<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, WebhookEvent};
use App\Services\EvolutionService;
use App\Services\BusinessHours;
use App\Services\MediaService;
use App\Support\{Jid, Marcadores, PhoneNumber, TenantContext};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProcessEvolutionWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    private const TIPOS_MIDIA = [
        'imageMessage'               => 'image',
        'videoMessage'               => 'video',
        'audioMessage'               => 'audio',
        'documentMessage'            => 'document',
        'stickerMessage'             => 'sticker',
        'documentWithCaptionMessage' => 'document',
    ];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $evento = WebhookEvent::find($this->webhookEventId);

        if (! $evento || $evento->processado_em) {
            return;
        }

        $canal = Channel::withoutGlobalScope('tenant')->find($evento->channel_id);

        if (! $canal) {
            $evento->update(['processado_em' => now(), 'erro' => 'canal inexistente']);

            return;
        }

        TenantContext::runAs($canal->tenant_id, function () use ($evento, $canal) {
            try {
                match (strtolower((string) $evento->evento)) {
                    'messages.upsert'   => $this->mensagemRecebida($canal, $evento->payload),
                    'messages.update'   => $this->statusAtualizado($canal, $evento->payload),
                    'connection.update' => $this->conexaoAtualizada($canal, $evento->payload),
                    default             => null,
                };
                $evento->update(['processado_em' => now(), 'erro' => null]);
            } catch (\Throwable $e) {
                // Payload inesperado nao pode derrubar a fila: registra e segue.
                $evento->update(['processado_em' => now(), 'erro' => $e->getMessage()]);
            }
        });
    }

    private function mensagemRecebida(Channel $canal, array $payload): void
    {
        $data = Arr::get($payload, 'data', []);

        if (Arr::get($data, 'key.fromMe')) {
            return; // eco do que nos mesmos enviamos
        }

        $externalId = Arr::get($data, 'key.id');
        $origem = $this->resolverOrigem($canal, $data);

        if (! $origem || ! $externalId) {
            throw new \RuntimeException('remetente ou id ausente no payload');
        }

        $conteudo = $this->identificarConteudo(Arr::get($data, 'message', []));

        if (! $conteudo) {
            return; // tipo que ainda nao tratamos (localizacao, contato, enquete)
        }

        $mensagem = DB::transaction(function () use ($canal, $origem, $externalId, $conteudo) {
            $contato = $origem['contato'];

            // Nao e firstOrCreate: se o ultimo atendimento foi encerrado, o
            // cliente que volta abre uma conversa NOVA, com comeco e fim
            // proprios, e a arquivada preserva o historico dela.
            $conversa = Conversation::abertaOuNova($canal->id, $contato->id, $canal->tenant_id);

            $mensagem = Message::updateOrCreate(
                ['channel_id' => $canal->id, 'external_id' => $externalId],
                [
                    'tenant_id'      => $canal->tenant_id,
                    'conversation_id' => $conversa->id,
                    'direcao'        => 'in',
                    'remetente_nome' => $origem['remetente_nome'],
                    'remetente_jid'  => $origem['remetente_jid'],
                    'tipo'           => $conteudo['tipo'],
                    'corpo'          => $conteudo['corpo'] ?? null,
                    'legenda'        => $conteudo['legenda'] ?? null,
                    'media_mime'     => $conteudo['mime'] ?? null,
                    'media_nome'     => $conteudo['nome'] ?? null,
                    'media_duracao'  => $conteudo['duracao'] ?? null,
                    'status'         => Message::STATUS_DELIVERED,
                    'enviada_em'     => now(),
                ],
            );

            if ($mensagem->wasRecentlyCreated) {
                $conversa->increment('nao_lidas');
                $conversa->update(['ultima_msg_em' => now()]);
            }

            return $mensagem;
        });

        // Fora da transacao de proposito: o download e uma chamada HTTP que pode
        // levar segundos, e transacao aberta esse tempo todo prende conexao.
        if ($conteudo['tipo'] !== 'text' && $mensagem->wasRecentlyCreated) {
            $this->baixarMidia($canal, $mensagem, $externalId);
        }

        if ($mensagem->wasRecentlyCreated) {
            broadcast(new MessageStored($mensagem->refresh()));
            $this->talvezResponderAutomaticamente($canal, $mensagem);
        }
    }

    // Tres travas, e cada uma existe por um motivo concreto:
    //  1. Grupo nunca: bairro com 40 mensagens a noite viraria 40 respostas na
    //     frente de todos os clientes daquele bairro.
    //  2. Uma vez por conversa, rearmando quando um humano responde: cinco
    //     mensagens as 22h nao podem gerar cinco respostas identicas.
    //  3. Marcada como automatica, para nao mover a conversa de Novos — senao o
    //     cliente espera a noite inteira e de manha ninguem ve.
    private function talvezResponderAutomaticamente(Channel $canal, Message $mensagem): void
    {
        $conversa = $mensagem->conversation;

        if (! $conversa || $conversa->contact?->eGrupo()) {
            return;
        }

        $conta = Tenant::find($canal->tenant_id);

        if (! $conta || ! $conta->resposta_automatica_ativa) {
            return;
        }

        $texto = trim((string) $conta->resposta_automatica_texto);

        if ($texto === '') {
            return;
        }

        $horas = new BusinessHours($conta);

        // Enquanto nao houver chatbot, team_id vem nulo e cai no canal/conta —
        // exatamente o comportamento anterior. Quando o bot rotear, o horario
        // passa a ser o da equipe que recebeu a conversa.
        $equipe = $conversa->team;

        if (! $horas->configurado($canal, $equipe) || $horas->abertoEm($mensagem->created_at ?? now(), $canal, $equipe)) {
            return;
        }

        $ultimaHumana = $conversa->messages()
            ->where('direcao', 'out')
            ->where('automatica', false)
            ->max('id');

        $jaRespondeu = $conversa->messages()
            ->where('automatica', true)
            ->when($ultimaHumana, fn ($q) => $q->where('id', '>', $ultimaHumana))
            ->exists();

        if ($jaRespondeu) {
            return;
        }

        $automatica = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $canal->id,
            'direcao'         => 'out',
            'automatica'      => true,
            'tipo'            => 'text',
            'corpo'           => Marcadores::aplicar(
                $texto,
                $conversa,
                null,
                $horas->proximaAberturaLegivel($mensagem->created_at ?? now(), $canal, $equipe),
            ),
            'status'          => Message::STATUS_QUEUED,
        ]);

        // ultima_msg_em NAO e atualizado de proposito: a espera do cliente comecou
        // quando ele escreveu, e em Novos a fila e ordenada por isso.
        SendTextMessage::dispatch($automatica->id);
    }

    private function identificarConteudo(array $msg): ?array
    {
        $texto = Arr::get($msg, 'conversation') ?? Arr::get($msg, 'extendedTextMessage.text');

        if ($texto !== null && $texto !== '') {
            return ['tipo' => 'text', 'corpo' => $texto];
        }

        foreach (self::TIPOS_MIDIA as $chave => $tipoPadrao) {
            if (! Arr::has($msg, $chave)) {
                continue;
            }

            $m = Arr::get($msg, $chave);

            if ($chave === 'documentWithCaptionMessage') {
                $m = Arr::get($m, 'message.documentMessage', $m);
            }

            $mime = (string) Arr::get($m, 'mimetype', '');

            return [
                'tipo'    => $mime !== '' ? app(MediaService::class)->tipoPorMime($mime) : $tipoPadrao,
                'legenda' => Arr::get($m, 'caption'),
                'mime'    => $mime !== '' ? $mime : null,
                'nome'    => Arr::get($m, 'fileName'),
                'duracao' => Arr::get($m, 'seconds'),
            ];
        }

        return null;
    }

    private function baixarMidia(Channel $canal, Message $mensagem, string $externalId): void
    {
        try {
            $r = app(EvolutionService::class)->getMediaBase64($canal->instance_name, $externalId);
            $base64 = Arr::get($r, 'base64');

            if (! $base64) {
                throw new \RuntimeException('a Evolution nao devolveu o arquivo');
            }

            $meta = app(MediaService::class)->guardarBase64(
                $mensagem->conversation,
                $base64,
                (string) (Arr::get($r, 'mimetype') ?: $mensagem->media_mime ?: 'application/octet-stream'),
                Arr::get($r, 'fileName') ?: $mensagem->media_nome,
            );

            $mensagem->update([
                'media_path'    => $meta['path'],
                'media_mime'    => $meta['mime'],
                'media_nome'    => $meta['nome'],
                'media_tamanho' => $meta['tamanho'],
                'erro'          => null,
            ]);

            // So audio que ENTRA: transcrever o que nos mesmos gravamos custaria
            // o dobro de CPU pelo que o atendente ja sabe que disse.
            if ($mensagem->tipo === 'audio' && $mensagem->direcao === 'in') {
                $mensagem->update(['transcricao_status' => 'pendente']);
                TranscribeAudio::dispatch($mensagem->id);
            }
        } catch (\Throwable $e) {
            // A mensagem fica no historico com o erro: legenda e contexto nao se
            // perdem, e o download pode ser refeito depois pelo external_id.
            $mensagem->update(['erro' => mb_substr('midia: '.$e->getMessage(), 0, 500)]);
        }
    }

    private function statusAtualizado(Channel $canal, array $payload): void
    {
        $externalId = Arr::get($payload, 'data.keyId') ?? Arr::get($payload, 'data.key.id');

        if (! $externalId) {
            return;
        }

        $novo = match (strtoupper((string) Arr::get($payload, 'data.status'))) {
            'DELIVERY_ACK', 'DELIVERED' => Message::STATUS_DELIVERED,
            'READ', 'PLAYED'            => Message::STATUS_READ,
            'SERVER_ACK', 'SENT'        => Message::STATUS_SENT,
            'ERROR'                     => Message::STATUS_FAILED,
            default                     => null,
        };

        if (! $novo) {
            return;
        }

        $mensagem = Message::where('channel_id', $canal->id)
            ->where('external_id', $externalId)
            ->first();

        if ($mensagem) {
            $mensagem->update(['status' => $novo]);
            broadcast(new MessageStored($mensagem));
        }
    }

    private function conexaoAtualizada(Channel $canal, array $payload): void
    {
        $estado = Arr::get($payload, 'data.state') ?? Arr::get($payload, 'data.connection');

        $canal->forceFill([
            'status'       => $estado ?: 'desconhecido',
            'conectado_em' => $estado === 'open' ? now() : $canal->conectado_em,
        ])->saveQuietly();

        // O payload de connection.update nao traz o numero. Quando a conexao
        // abre, perguntamos a Evolution qual e — para o painel mostrar de qual
        // chip cada canal se trata.
        if ($estado === 'open' && ! $canal->telefone_e164) {
            try {
                $info = app(EvolutionService::class)->instanceInfo($canal->instance_name);
                $primeiro = collect($info)->first();
                $jid = data_get($primeiro, 'ownerJid') ?? data_get($primeiro, 'instance.owner');

                if ($telefone = PhoneNumber::toE164($jid)) {
                    $canal->forceFill(['telefone_e164' => $telefone])->saveQuietly();
                }
            } catch (\Throwable $e) {
                // numero e informativo: nao vale derrubar o processamento
            }
        }
    }

    // Pessoa e grupo chegam pelo mesmo evento, diferenciados pelo dominio do
    // JID. Em grupo o remoteJid identifica o GRUPO e quem falou vem em
    // key.participant — sem separar isso, cada participante viraria um contato
    // e a conversa do grupo se estilhacaria.
    private function resolverOrigem(Channel $canal, array $data): ?array
    {
        $bruto = Arr::get($data, 'key.remoteJid');
        $jid = Jid::limpar($bruto);

        if (! $jid) {
            return null;
        }

        if (Jid::eGrupo($jid)) {
            $contato = Contact::firstOrCreate(
                ['tenant_id' => $canal->tenant_id, 'jid' => $jid],
                ['tipo' => Contact::GRUPO, 'nome' => $this->nomeDoGrupo($canal, $jid)],
            );

            return [
                'contato'        => $contato,
                'remetente_nome' => Arr::get($data, 'pushName'),
                'remetente_jid'  => Jid::limpar(Arr::get($data, 'key.participant')),
            ];
        }

        $telefone = PhoneNumber::toE164($jid);

        if (! $telefone) {
            return null;
        }

        $contato = Contact::firstOrCreate(
            ['tenant_id' => $canal->tenant_id, 'jid' => $jid],
            ['tipo' => Contact::PESSOA, 'telefone_e164' => $telefone, 'nome' => Arr::get($data, 'pushName')],
        );

        return ['contato' => $contato, 'remetente_nome' => null, 'remetente_jid' => null];
    }

    private function nomeDoGrupo(Channel $canal, string $jid): ?string
    {
        try {
            $info = app(EvolutionService::class)->groupInfo($canal->instance_name, $jid);

            return Arr::get($info, 'subject') ?: null;
        } catch (\Throwable $e) {
            // nome e cosmetico: o grupo funciona sem ele
            return null;
        }
    }
}
