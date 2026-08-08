<?php

namespace App\Jobs;

use App\Events\MessageStored;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, WebhookEvent};
use App\Services\EvolutionService;
use App\Services\BusinessHours;
use App\Services\ChatbotMotor;
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
            $this->mensagemEnviadaPorFora($canal, $data);

            return;
        }

        $externalId = Arr::get($data, 'key.id');
        $origem = $this->resolverOrigem($canal, $data);

        if (! $origem || ! $externalId) {
            throw new \RuntimeException('remetente ou id ausente no payload');
        }

        // Reacao NAO e mensagem: nao pode virar balao na conversa.
        if ($reacao = Arr::get($data, 'message.reactionMessage')) {
            $this->registrarReacao($canal, $reacao);

            return;
        }

        $conteudo = $this->identificarConteudo(Arr::get($data, 'message', []));
        $citadoExternalId = $this->citado($data);

        // So faz sentido em grupo: em conversa de um para um, toda mensagem ja e para nos.
        $mencionado = $origem['contato']->eGrupo() && $this->mencionaOCanal($data, $canal);

        if (! $conteudo) {
            return; // tipo que ainda nao tratamos (localizacao, contato, enquete)
        }

        $mensagem = DB::transaction(function () use ($canal, $origem, $externalId, $conteudo, $citadoExternalId, $mencionado) {
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
                    // Pode nao achar: o cliente e capaz de citar mensagem anterior a
                    // instalacao do OnChat, que nunca esteve neste banco. Fica null e a
                    // conversa segue — a alternativa seria recusar a mensagem inteira.
                    'responde_a_id'  => Message::acharPorExternalId($canal->id, $citadoExternalId)?->id,
                    'mencao'         => $mencionado,
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

                // A marca fica na CONVERSA tambem: a lista precisa saber sem abrir cada uma.
                // E nao se apaga por mensagem nova — so quando alguem realmente abre.
                if ($mencionado) {
                    $conversa->forceFill(['mencao_em' => now()])->saveQuietly();
                }
                // ultima_entrada_em junto: e a mensagem do CLIENTE que reabre a
                // janela de 24h, e so ela. Resposta nossa nao reabre nada — na regra
                // da Meta, a janela pertence a quem procurou.
                $conversa->update([
                    'ultima_msg_em'     => now(),
                    'ultima_entrada_em' => now(),
                ]);
            }

            return $mensagem;
        });

        // Fora da transacao de proposito: o download e uma chamada HTTP que pode
        // levar segundos, e transacao aberta esse tempo todo prende conexao.
        if ($conteudo['tipo'] !== 'text' && $mensagem->wasRecentlyCreated) {
            $this->baixarMidia($canal, $mensagem, $externalId);
        }

        // SEQUENCIAS. Duas coisas de uma vez, e a ordem importa:
        //
        // 1. o cliente FALOU, entao qualquer jornada em andamento para. Sem isto a sequencia
        //    vira perseguicao — ele responde, alguem atende, e a maquina continua mandando
        //    "notou que voce nao respondeu?" no dia seguinte.
        // 2. se esta e a PRIMEIRA conversa dele, comeca a jornada de quem chega.
        $this->talvezSequencias($mensagem);

        // "PARAR". O cliente que pede para sair da lista tem de sair na hora, e sem depender
        // de alguem ler a mensagem: pedido ignorado vira denuncia, e denuncia derruba o numero.
        $this->talvezSairDaLista($mensagem);

        // A nota da pesquisa chega como conversa NOVA, porque a anterior foi encerrada. Se
        // for nota, ela e gravada na conversa encerrada e esta aqui se fecha sozinha — senao a
        // pesquisa geraria fila em Novos com conversas cujo unico conteudo e o numero 5.
        if ($mensagem->wasRecentlyCreated
            && app(\App\Services\PesquisaDeSatisfacao::class)->talvezRegistrar($mensagem)) {
            return;
        }

        if ($mensagem->wasRecentlyCreated) {
            broadcast(new MessageStored($mensagem->refresh()));

            // O bot tem a primeira palavra. Se ele atendeu, a resposta automatica
            // de fora do horario fica calada: duas mensagens de robo seguidas e a
            // pior experiencia possivel, e o bot tem aviso proprio de horario.
            if (! app(ChatbotMotor::class)->talvezAtender($canal, $mensagem)) {
                $this->talvezResponderAutomaticamente($canal, $mensagem);
            }
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

        if (! $conversa || $conversa->contact?->eGrupo() || $conversa->contact?->bloqueado()) {
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

    /**
     * O id da mensagem citada, quando o cliente respondeu alguma.
     *
     * O Baileys guarda o contextInfo DENTRO do conteudo, e o nome da chave muda com o tipo:
     * extendedTextMessage para texto, imageMessage para foto, e assim por diante. Varrer um
     * nivel evita uma lista de tipos que envelheceria calada — tipo novo entraria e a citacao
     * dele simplesmente nao apareceria, sem erro.
     */
    /**
     * Sequencias: o cliente respondeu, e talvez seja a primeira vez que ele fala.
     *
     * Parar vem antes de inscrever de proposito. Se fosse ao contrario, a primeira mensagem
     * dele inscreveria e no mesmo instante pararia a inscricao recem-criada — e ninguem
     * receberia jornada nenhuma.
     */
    private function talvezSequencias(Message $mensagem): void
    {
        $contato = $mensagem->conversation?->contact;

        if (! $contato) {
            return;
        }

        $cadenciador = app(\App\Services\Cadenciador::class);

        $cadenciador->clienteRespondeu($contato);

        $primeira = \App\Models\Conversation::withoutGlobalScope('tenant')
            ->where('contact_id', $contato->id)
            ->count() === 1;

        if ($primeira) {
            $cadenciador->inscrever(
                \App\Models\Sequence::PRIMEIRA_CONVERSA,
                $contato,
                $mensagem->conversation,
            );
        }
    }

    /**
     * O cliente pediu para sair da lista de campanhas.
     *
     * Registra tambem um evento na conversa: sem isso o atendente responderia normalmente sem
     * saber que aquela pessoa acabou de pedir para nao ser mais incomodada.
     */
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

    /** O cliente reagiu a uma mensagem. text vazio quer dizer que ele TIROU a reacao. */
    private function registrarReacao(Channel $canal, array $reacao): void
    {
        $alvo = Message::acharPorExternalId($canal->id, (string) Arr::get($reacao, 'key.id'));

        if (! $alvo) {
            return;
        }

        $alvo->update(['reacao_cliente' => ((string) Arr::get($reacao, 'text', '')) ?: null]);

        broadcast(new \App\Events\MessageStored($alvo->refresh()));
    }

    /**
     * O id da mensagem citada, quando o cliente respondeu alguma.
     *
     * PROCURA EM DOIS LUGARES, e o segundo foi um defeito que passou despercebido por dias.
     *
     * Esta versao da Evolution manda o contextInfo em data.contextInfo — irmao de "message", e
     * nao dentro dele. A funcao so olhava dentro do conteudo, e o resultado foi silencioso:
     * cinco clientes citaram mensagens e NENHUMA citacao apareceu na tela. Nada quebrou, nada
     * foi para o log; a faixa simplesmente nao existia.
     *
     * Os dois caminhos ficam porque versoes diferentes da Evolution mandam de jeitos
     * diferentes, e nao ha como saber qual esta do outro lado.
     */
    private function citado(array $data): ?string
    {
        if ($id = Arr::get($data, 'contextInfo.stanzaId')) {
            return (string) $id;
        }

        foreach ((array) Arr::get($data, 'message', []) as $conteudo) {
            if (is_array($conteudo) && ($id = Arr::get($conteudo, 'contextInfo.stanzaId'))) {
                return (string) $id;
            }
        }

        return Arr::get($data, 'message.contextInfo.stanzaId');
    }

    /**
     * O grupo mencionou ESTE numero?
     *
     * Num grupo movimentado, a unica mensagem que e sua e aquela em que te chamam. Sem separar
     * isso, o atendente le duzentas mensagens para achar uma — ou desiste e nao le nenhuma,
     * que e o que acontece de verdade.
     *
     * DUAS FORMAS DE ACHAR, porque o WhatsApp esta trocando o jeito de identificar gente:
     *
     * 1. mentionedJid: a lista oficial. Compara so os digitos, com as duas formas do numero
     *    brasileiro — com e sem o nono digito — porque o proprio Baileys informa o nosso numero
     *    sem ele.
     *
     * 2. o texto: mencao aparece escrita como "@numero" no corpo. Serve de rede quando o
     *    WhatsApp manda o novo identificador @lid, que nao da para casar com telefone.
     *
     * Se as duas falharem a mensagem entra como qualquer outra do grupo — nunca ao contrario.
     * Marcar mencao onde nao houve treinaria o atendente a ignorar a marca.
     */
    private function mencionaOCanal(array $data, Channel $canal): bool
    {
        $meus = \App\Support\PhoneNumber::variantes((string) $canal->telefone_e164);

        if ($meus === []) {
            return false;
        }

        $mencionados = (array) (Arr::get($data, 'contextInfo.mentionedJid')
            ?: Arr::get($data, 'message.extendedTextMessage.contextInfo.mentionedJid', []));

        foreach ($mencionados as $jid) {
            $digitos = preg_replace('/\D+/', '', (string) $jid);

            foreach ($meus as $meu) {
                if ($digitos === preg_replace('/\D+/', '', $meu)) {
                    return true;
                }
            }
        }

        $texto = (string) (Arr::get($data, 'message.conversation')
            ?: Arr::get($data, 'message.extendedTextMessage.text', ''));

        foreach ($meus as $meu) {
            if (str_contains($texto, '@'.preg_replace('/\D+/', '', $meu))) {
                return true;
            }
        }

        return false;
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

                    // O canal nasceu sem nome de verdade porque ninguem tinha um para dar
                    // antes de conectar. Agora tem: o proprio numero. So troca se o nome ainda
                    // for o provisorio — quem ja batizou o canal nao pode ver o nome dele
                    // sumir sozinho.
                    if ($canal->temNomeProvisorio()) {
                        $canal->forceFill([
                            'nome' => \App\Support\PhoneNumber::discavel($telefone) ?: $telefone,
                        ])->saveQuietly();
                    }
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
    /**
     * A mensagem que saiu do NOSSO numero sem passar por aqui.
     *
     * O atendente abriu o WhatsApp no proprio celular e respondeu de la. Ate agora isso era
     * jogado fora como "eco", e o sistema perdia metade da conversa: no painel o atendimento
     * ficava parado na pergunta do cliente, e quem abrisse depois concluiria que ele estava
     * sem resposta — e responderia de novo.
     *
     * O QUE SEPARA ECO DE MENSAGEM NOVA E O BANCO, e nao o payload. Toda mensagem que saiu por
     * aqui foi gravada com o id do provedor antes de existir eco; se o id ja e conhecido, o
     * evento e o eco e nao ha nada a fazer. Se nao e, alguem falou por fora.
     */
    private function mensagemEnviadaPorFora(Channel $canal, array $data): void
    {
        $externalId = Arr::get($data, 'key.id');

        if (! $externalId || Message::acharPorExternalId($canal->id, $externalId)) {
            return;
        }

        /*
         * SEM O pushName.
         *
         * Em mensagem nossa ele e o NOSSO nome, e o resolverOrigem usa esse campo para batizar
         * contato novo. Mandar mensagem do celular para um numero desconhecido criaria o
         * contato com o nome do proprio atendente — e o erro so apareceria semanas depois,
         * numa lista cheia de contatos chamados "Rafael".
         */
        $origem = $this->resolverOrigem($canal, Arr::except($data, ['pushName']));

        if (! $origem) {
            return;
        }

        // Reacao dada pelo celular tambem nao vira balao na conversa.
        if ($reacao = Arr::get($data, 'message.reactionMessage')) {
            $this->registrarReacao($canal, $reacao);

            return;
        }

        $conteudo = $this->identificarConteudo(Arr::get($data, 'message', []));

        if (! $conteudo) {
            return;
        }

        $citadoExternalId = $this->citado($data);

        $mensagem = DB::transaction(function () use ($canal, $origem, $externalId, $conteudo, $citadoExternalId) {
            $conversa = Conversation::abertaOuNova($canal->id, $origem['contato']->id, $canal->tenant_id);

            $mensagem = Message::updateOrCreate(
                ['channel_id' => $canal->id, 'external_id' => $externalId],
                [
                    'tenant_id'       => $canal->tenant_id,
                    'conversation_id' => $conversa->id,
                    'direcao'         => 'out',
                    'tipo'            => $conteudo['tipo'],
                    'corpo'           => $conteudo['corpo'] ?? null,
                    'responde_a_id'   => Message::acharPorExternalId($canal->id, $citadoExternalId)?->id,
                    'legenda'         => $conteudo['legenda'] ?? null,
                    'media_mime'      => $conteudo['mime'] ?? null,
                    'media_nome'      => $conteudo['nome'] ?? null,
                    'media_duracao'   => $conteudo['duracao'] ?? null,
                    // Ja saiu: quem entregou foi o WhatsApp do aparelho, e nao a nossa fila.
                    'status'          => Message::STATUS_DELIVERED,
                    'enviada_em'      => now(),
                    'por_fora'        => true,
                ],
            );

            if ($mensagem->wasRecentlyCreated) {
                /*
                 * ultima_msg_em SIM, ultima_entrada_em NAO, e nao_lidas nem encosta.
                 *
                 * A conversa sobe na lista porque houve movimento. Mas a janela de 24h pertence
                 * a quem PROCUROU, e quem falou aqui fomos nos. E nao lidas conta mensagem de
                 * cliente: somar a nossa propria resposta faria o contador acusar trabalho que
                 * nao existe.
                 */
                $conversa->update(['ultima_msg_em' => now()]);
            }

            return $mensagem;
        });

        if ($conteudo['tipo'] !== 'text' && $mensagem->wasRecentlyCreated) {
            $this->baixarMidia($canal, $mensagem, $externalId);
        }

        if ($mensagem->wasRecentlyCreated) {
            broadcast(new MessageStored($mensagem->refresh()));
        }
    }

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

        // O jid vem explicito porque e o que a Evolution conhece: reconstruir a partir do
        // telefone perderia formato que o provedor use no futuro.
        $contato = Contact::acharOuCriarPorTelefone(
            $telefone,
            ['tenant_id' => $canal->tenant_id, 'nome' => Arr::get($data, 'pushName')],
            $jid,
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
