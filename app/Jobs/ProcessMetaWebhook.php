<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\{Channel, Contact, Conversation, Message, WebhookEvent};
use App\Services\Canais\Enviadores;
use App\Services\Canais\MetaCloudEnviador;
use App\Services\ChatbotMotor;
use App\Services\MediaService;
use App\Support\Jid;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Transforma o evento do WhatsApp oficial em conversa e mensagem.
 *
 * Espelha o ProcessEvolutionWebhook nas decisoes que importam — conversa nova quando a
 * anterior foi encerrada, idempotencia por external_id, o bot com a primeira palavra —
 * porque sao regras do OnChat e nao do provedor. O que muda e o formato do payload.
 */
class ProcessMetaWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 20, 60];

    public function __construct(public int $webhookEventId) {}

    public function handle(): void
    {
        $evento = WebhookEvent::withoutGlobalScopes()->find($this->webhookEventId);

        if (! $evento || $evento->processado_em) {
            return; // reentrega da Meta de algo que ja tratamos
        }

        $canal = Channel::withoutGlobalScope('tenant')->find($evento->channel_id);

        if (! $canal) {
            $evento->update(['erro' => 'canal nao existe mais', 'processado_em' => now()]);

            return;
        }

        try {
            TenantContext::runAs($canal->tenant_id, function () use ($canal, $evento) {
                $valor = data_get($evento->payload, 'entry.0.changes.0.value', []);

                // Recibos primeiro: sao mais frequentes e nao criam nada.
                foreach ((array) data_get($valor, 'statuses', []) as $recibo) {
                    $this->recibo($canal, $recibo);
                }

                foreach ((array) data_get($valor, 'messages', []) as $bruta) {
                    $this->mensagem($canal, $valor, $bruta);
                }
            });

            $evento->update(['processado_em' => now(), 'erro' => null]);
        } catch (\Throwable $e) {
            // Registra e NAO relanca: payload torto da Meta nao pode derrubar a fila em
            // cascata. O evento cru fica gravado para investigar.
            Log::warning('webhook da meta falhou', [
                'evento' => $evento->id,
                'erro'   => $e->getMessage(),
            ]);

            $evento->update(['erro' => mb_substr($e->getMessage(), 0, 500), 'processado_em' => now()]);
        }
    }

    /** Mensagem recebida do cliente. */
    private function mensagem(Channel $canal, array $valor, array $bruta): void
    {
        $wamid = (string) ($bruta['id'] ?? '');
        $de = (string) ($bruta['from'] ?? '');

        if ($wamid === '' || $de === '') {
            throw new \RuntimeException('mensagem sem id ou sem remetente');
        }

        // Reacao NAO e mensagem: e um enfeite sobre uma mensagem que ja existe. Tratar
        // como mensagem faria a conversa ganhar um balao vazio a cada polegar levantado.
        if (($bruta['type'] ?? '') === 'reaction') {
            $this->reacao($canal, $bruta);

            return;
        }

        $conteudo = $this->conteudo($bruta);
        // A Meta poe a citacao em context.id, e o valor e o wamid da mensagem citada.
        $citadoWamid = ((string) data_get($bruta, 'context.id', '')) ?: null;

        if (! $conteudo) {
            // Tipo que ainda nao tratamos. Nao e erro: o evento fica gravado e o
            // atendente nao ve mensagem fantasma pela metade.
            Log::info('tipo de mensagem da meta ainda nao tratado', [
                'tipo'  => $bruta['type'] ?? '?',
                'canal' => $canal->id,
            ]);

            return;
        }

        // A Meta manda o telefone sem o sinal de mais; o nosso cadastro guarda com.
        $e164 = '+'.preg_replace('/\D+/', '', $de);
        $nome = (string) data_get($valor, 'contacts.0.profile.name', '');

        $mensagem = DB::transaction(function () use ($canal, $e164, $nome, $wamid, $conteudo, $citadoWamid) {
            // Procura pelas duas grafias do numero antes de criar: a Meta identifica
            // celular brasileiro antigo SEM o nono digito, e o mesmo cliente pode ja
            // existir com a grafia longa, vindo da Evolution ou de uma planilha.
            $contato = Contact::acharOuCriarPorTelefone($e164, [
                'tenant_id' => $canal->tenant_id,
                // Nome do perfil so no cadastro NOVO: se o atendente corrigiu o nome, o
                // WhatsApp nao pode desfazer isso a cada mensagem.
                'nome'      => $nome !== '' ? $nome : null,
            ]);

            $conversa = Conversation::abertaOuNova($canal->id, $contato->id, $canal->tenant_id);

            $mensagem = Message::updateOrCreate(
                ['channel_id' => $canal->id, 'external_id' => $wamid],
                [
                    'tenant_id'       => $canal->tenant_id,
                    'conversation_id' => $conversa->id,
                    'direcao'         => 'in',
                    'remetente_nome'  => $nome !== '' ? $nome : null,
                    'remetente_jid'   => $contato->jid,
                    'tipo'            => $conteudo['tipo'],
                    'corpo'           => $conteudo['corpo'] ?? null,
                    // Pode nao achar: o cliente cita mensagem que nunca passou por aqui.
                    // Fica null e a conversa segue.
                    'responde_a_id'   => Message::acharPorExternalId($canal->id, $citadoWamid)?->id,
                    'legenda'         => $conteudo['legenda'] ?? null,
                    'media_mime'      => $conteudo['mime'] ?? null,
                    'media_nome'      => $conteudo['nome'] ?? null,
                    'status'          => Message::STATUS_DELIVERED,
                    'enviada_em'      => now(),
                ],
            );

            if ($mensagem->wasRecentlyCreated) {
                $conversa->increment('nao_lidas');
                // ultima_entrada_em: e a mensagem do cliente que reabre a janela de 24h.
                $conversa->update([
                    'ultima_msg_em'     => now(),
                    'ultima_entrada_em' => now(),
                ]);
            }

            return $mensagem;
        });

        if (! $mensagem->wasRecentlyCreated) {
            return; // reentrega: nao apita nem chama o bot de novo
        }

        // "PARAR": mesmo tratamento do canal por QR.
        $this->talvezSairDaLista($mensagem);

        // A nota da pesquisa chega como conversa NOVA, porque a anterior foi encerrada. Sendo
        // nota, ela e gravada na conversa encerrada e esta aqui se fecha sozinha — senao a
        // pesquisa geraria fila em Novos com conversas cujo unico conteudo e o numero 5.
        if (app(\App\Services\PesquisaDeSatisfacao::class)->talvezRegistrar($mensagem)) {
            return;
        }

        // De onde a pessoa veio. Antes do broadcast para o atendente ja abrir a conversa
        // vendo a origem.
        $this->guardarOrigem($mensagem->conversation, (array) data_get($bruta, 'referral', []));

        // Antes de avisar a tela: sem isso o atendente ve a bolha de audio chegar e
        // clicar em play num arquivo que ainda nao existe.
        if (! empty($conteudo['media_id'])) {
            $this->baixarMidia($canal, $mensagem, (string) $conteudo['media_id']);
        }

        broadcast(new MessageStored($mensagem->refresh()));

        // O bot tem a primeira palavra, igual ao canal nao oficial.
        app(ChatbotMotor::class)->talvezAtender($canal, $mensagem);
    }

    /**
     * De onde veio esta conversa.
     *
     * A Meta manda o bloco "referral" junto da PRIMEIRA mensagem de quem chegou por um
     * anuncio Click-to-WhatsApp — e somente junto dela. Nao ha consulta depois que devolva
     * isso: nao guardar aqui e perder.
     *
     * NAO SOBRESCREVE, e essa e a decisao que importa. Se o cliente clicar em outro anuncio
     * dentro da mesma conversa aberta, a Meta manda referral de novo. Trocar faria o
     * relatorio de "conversas por anuncio" mudar de atribuicao retroativamente — e numero
     * que muda sozinho no passado nao serve para decidir orcamento.
     *
     * O segundo anuncio nao se perde: conversa encerrada e depois reaberta vira conversa
     * NOVA pelo abertaOuNova, com origem propria. Entao a segunda campanha aparece, e no
     * lugar certo.
     *
     * As chaves sao traduzidas aqui, na borda. O bruto da Meta fica no webhook_events para
     * quem precisar investigar; o resto do sistema nao deveria aprender ingles de API.
     */
    private function guardarOrigem(Conversation $conversa, array $referral): void
    {
        if ($referral === [] || $conversa->origem_tipo !== null) {
            return;
        }

        $conversa->update([
            'origem_tipo' => (string) (data_get($referral, 'source_type') ?: 'ad'),
            'origem_id'   => data_get($referral, 'source_id'),
            'origem'      => array_filter([
                'titulo' => data_get($referral, 'headline'),
                'texto'  => data_get($referral, 'body'),
                'url'    => data_get($referral, 'source_url'),
                // ctwa_clid e o identificador do CLIQUE. E o que permite casar esta conversa
                // com o gasto do anuncio no relatorio da Meta depois. Sem ele, "veio de
                // anuncio" nao liga a dinheiro nenhum, e a conta de custo por conversa nao
                // fecha.
                'clique' => data_get($referral, 'ctwa_clid'),
                'midia'  => data_get($referral, 'media_type'),
                'imagem' => data_get($referral, 'image_url') ?: data_get($referral, 'thumbnail_url'),
            ]),
        ]);
    }

    /**
     * Traz o arquivo para o disco.
     *
     * Espelha o que o webhook da Evolution faz, inclusive na escolha de NAO derrubar a
     * mensagem quando o download falha: legenda, remetente e hora nao se perdem por causa
     * do arquivo, e o erro fica gravado na propria mensagem — que e onde o atendente olha.
     * O id continua no payload cru, entao dar para refazer depois.
     */
    private function baixarMidia(Channel $canal, Message $mensagem, string $mediaId): void
    {
        try {
            $enviador = app(Enviadores::class)->para($canal);

            if (! $enviador instanceof MetaCloudEnviador) {
                return;
            }

            $arquivo = $enviador->baixarMidia($canal, $mediaId);

            $guardado = app(MediaService::class)->guardarBytes(
                $mensagem->conversation,
                $arquivo['bytes'],
                $arquivo['mime'] ?: (string) $mensagem->media_mime ?: 'application/octet-stream',
                $mensagem->media_nome,
            );

            $novos = [
                'media_path'    => $guardado['path'],
                'media_mime'    => $guardado['mime'],
                'media_nome'    => $guardado['nome'],
                'media_tamanho' => $guardado['tamanho'],
                'erro'          => null,
            ];

            // A Meta nao manda a duracao no webhook, diferente da Evolution. Lemos do
            // arquivo que acabamos de baixar — e so para audio e video, porque em imagem e
            // documento a pergunta nao existe.
            if (in_array($mensagem->tipo, ['audio', 'video'], true)) {
                $duracao = app(MediaService::class)->duracaoSegundos(
                    Storage::disk('local')->path($guardado['path']),
                );

                if ($duracao !== null) {
                    $novos['media_duracao'] = $duracao;
                }
            }

            $mensagem->update($novos);

            // So audio que ENTRA, igual ao outro canal: transcrever o que nos mesmos
            // gravamos custaria CPU pelo que o atendente ja sabe que disse.
            if ($mensagem->tipo === 'audio' && $mensagem->direcao === 'in') {
                $mensagem->update(['transcricao_status' => 'pendente']);
                TranscribeAudio::dispatch($mensagem->id);
            }
        } catch (\Throwable $e) {
            $mensagem->update(['erro' => mb_substr('midia: '.$e->getMessage(), 0, 500)]);
        }
    }

    /**
     * Recibo de entrega: casa pelo wamid que guardamos ao enviar.
     *
     * Sem isso o atendente nao sabe se a mensagem chegou — e no canal oficial "enviada"
     * e "entregue" sao coisas diferentes que custam dinheiro diferente.
     */
    private function recibo(Channel $canal, array $recibo): void
    {
        $wamid = (string) ($recibo['id'] ?? '');
        $situacao = (string) ($recibo['status'] ?? '');

        if ($wamid === '' || $situacao === '') {
            return;
        }

        $mensagem = Message::where('channel_id', $canal->id)
            ->where('external_id', $wamid)
            ->first();

        if (! $mensagem) {
            return; // recibo de mensagem que nao saiu por aqui
        }

        $novo = match ($situacao) {
            'sent'      => Message::STATUS_SENT,
            'delivered' => Message::STATUS_DELIVERED,
            'read'      => Message::STATUS_READ,
            'failed'    => Message::STATUS_FAILED,
            default     => null,
        };

        if ($novo === null) {
            return;
        }

        // Nao retrocede: a Meta pode entregar "sent" depois de "read" fora de ordem, e
        // ver a mensagem voltar de lida para enviada faria o atendente reenviar.
        $ordem = [
            Message::STATUS_QUEUED    => 0,
            Message::STATUS_SENT      => 1,
            Message::STATUS_DELIVERED => 2,
            Message::STATUS_READ      => 3,
        ];

        $atual = $ordem[$mensagem->status] ?? -1;
        $proposto = $ordem[$novo] ?? -1;

        if ($novo !== Message::STATUS_FAILED && $proposto <= $atual) {
            return;
        }

        $mensagem->update([
            'status' => $novo,
            'erro'   => $novo === Message::STATUS_FAILED
                ? mb_substr((string) data_get($recibo, 'errors.0.title', 'falha informada pela Meta'), 0, 500)
                : $mensagem->erro,
        ]);

        broadcast(new MessageStored($mensagem->refresh()));
    }

    /**
     * O que veio na mensagem.
     *
     * Midia devolve o tipo e o mime, mas NAO baixa o arquivo: no caminho oficial o
     * download exige uma segunda chamada com o id da midia e um token, e entregar isso
     * pela metade deixaria mensagem sem conteudo na tela. Por ora fica registrada como
     * midia sem arquivo, e o atendente ve que existe.
     *
     * @return array<string, mixed>|null
     */
    /** O cliente pediu para sair da lista de campanhas. Mesmo tratamento do canal por QR. */
    private function talvezSairDaLista(Message $mensagem): void
    {
        $disparador = app(\App\Services\Disparador::class);

        if (! $disparador->pedidoDeSaida($mensagem->corpo)) {
            return;
        }

        $contato = $mensagem->conversation?->contact;

        if (! $contato || $contato->saiuDaLista()) {
            return;
        }

        $disparador->marcarSaida($contato);

        \App\Models\ConversationEvent::create([
            'tenant_id'       => $mensagem->tenant_id,
            'conversation_id' => $mensagem->conversation_id,
            'tipo'            => \App\Models\ConversationEvent::NOTA,
            'descricao'       => 'O cliente pediu para sair da lista de campanhas. '
                .'Não receberá mais disparos; a conversa normal continua.',
        ]);
    }

    /** O cliente reagiu a uma mensagem nossa. Emoji vazio quer dizer que ele TIROU a reacao. */
    private function reacao(Channel $canal, array $bruta): void
    {
        $alvo = Message::acharPorExternalId($canal->id, (string) data_get($bruta, 'reaction.message_id'));

        if (! $alvo) {
            // Reacao a mensagem que nunca passou por aqui. Nao e erro: nao ha onde mostrar.
            return;
        }

        $alvo->update(['reacao_cliente' => ((string) data_get($bruta, 'reaction.emoji', '')) ?: null]);

        broadcast(new \App\Events\MessageStored($alvo->refresh()));
    }

    private function conteudo(array $b): ?array
    {
        $tipo = (string) ($b['type'] ?? '');

        return match ($tipo) {
            'text' => ['tipo' => 'text', 'corpo' => (string) data_get($b, 'text.body', '')],

            'button' => ['tipo' => 'text', 'corpo' => (string) data_get($b, 'button.text', '')],

            // Resposta de botao ou de lista: para o chatbot, e o texto escolhido que
            // importa — e assim o menu do fluxo continua casando.
            'interactive' => ['tipo' => 'text', 'corpo' => (string) (
                data_get($b, 'interactive.button_reply.title')
                ?? data_get($b, 'interactive.list_reply.title')
                ?? ''
            )],

            'image', 'video', 'audio', 'document', 'sticker' => [
                'tipo'    => $tipo === 'sticker' ? 'image' : $tipo,
                'legenda' => data_get($b, $tipo.'.caption'),
                'mime'    => data_get($b, $tipo.'.mime_type'),
                'nome'    => data_get($b, $tipo.'.filename'),
                // O id e a UNICA forma de chegar ao arquivo: o webhook nao traz bytes
                // nem URL. Sem guardar isto, a midia fica perdida para sempre.
                'media_id' => data_get($b, $tipo.'.id'),
            ],

            default => null,
        };
    }
}
