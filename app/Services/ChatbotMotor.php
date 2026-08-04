<?php

namespace App\Services;

use App\Jobs\ContinuarFluxo;
use App\Jobs\SendTextMessage;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\ChatbotAction;
use App\Models\ChatbotEdge;
use App\Models\ChatbotStep;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;

/**
 * Percorre o fluxo montado no construtor: blocos com acoes em ordem, ligados por
 * arestas.
 *
 * As travas vieram inteiras do motor de arvore, porque sao a parte que da errado em
 * producao: nunca em grupo, sai de cena quando um humano assume, palavra de escape,
 * limite de tentativas, e tudo marcado como automatica.
 */
class ChatbotMotor
{
    public const ATIVO = 'ativo';

    public const CONCLUIDO = 'concluido';

    public const ESCAPOU = 'escapou';

    public const AGUARDA_MENU = 'menu';

    public const AGUARDA_PERGUNTA = 'pergunta';

    /** Teto de blocos por execucao: um fluxo A->B->A sem espera giraria para sempre. */
    private const MAX_BLOCOS = 25;

    /** @var array<int, string> textos a enviar nesta rodada, em ordem */
    private array $saidas = [];

    /**
     * O canal fica na instancia porque o percurso precisa poder ESVAZIAR as saidas
     * antes de entregar o controle a outro job (ver a acao Esperar). Sem isso, a
     * continuacao criaria as mensagens dela antes das que ainda estavam na fila
     * local e o cliente receberia na ordem trocada.
     */
    private ?Channel $canal = null;

    public function talvezAtender(Channel $canal, Message $mensagem): bool
    {
        $conversa = $mensagem->conversation;

        if (! $conversa || ! $this->podeAtender($conversa, $mensagem)) {
            return false;
        }

        $bot = Chatbot::publicadoPara($canal);

        if (! $bot) {
            return false;
        }

        $this->saidas = [];
        $this->canal = $canal;

        $agiu = $conversa->chatbot_estado === null
            ? $this->comecar($bot, $conversa, $canal)
            : $this->retomar($bot, $conversa, $mensagem);

        if ($agiu) {
            $this->despachar($conversa);
        }

        return $agiu;
    }

    // ------------------------------------------------------------------- travas

    private function podeAtender(Conversation $conversa, Message $mensagem): bool
    {
        // Mensagem nossa ou automatica nunca alimenta o bot: seria o bot
        // conversando com ele mesmo.
        if ($mensagem->automatica || $mensagem->direcao !== 'in') {
            return false;
        }

        // Grupo nunca. Bairro com 40 mensagens a noite viraria 40 fluxos na frente
        // de todos os clientes daquele bairro.
        if ($conversa->contact?->eGrupo()) {
            return false;
        }

        // Contato bloqueado nao recebe nada nosso. Interruptor de bloqueio que nao
        // impede o robo de responder nao bloqueia nada.
        if ($conversa->contact?->bloqueado()) {
            return false;
        }

        // Humano assumiu: o bot sai de cena e nao volta.
        if ($conversa->atendente_id) {
            return false;
        }

        if (in_array($conversa->chatbot_estado, [self::CONCLUIDO, self::ESCAPOU], true)) {
            return false;
        }

        // Alguem da equipe ja escreveu aqui. Mesmo sem assumir formalmente, quem
        // respondeu tomou a conversa para si.
        return ! $conversa->messages()
            ->where('direcao', 'out')
            ->where('automatica', false)
            ->exists();
    }

    // ---------------------------------------------------------------- comeco

    private function comecar(Chatbot $bot, Conversation $conversa, Channel $canal): bool
    {
        $inicio = $bot->inicio();
        $primeiro = $inicio?->destino();

        // Fluxo publicado sem inicio ligado nao atende. A validacao impede
        // publicar assim, mas se acontecer o certo e ficar quieto e deixar a
        // pessoa responder, nao travar o cliente.
        if (! $primeiro) {
            return false;
        }

        $aviso = trim((string) $bot->mensagem_fora_horario);

        if ($aviso !== '' && $this->foraDoHorario($conversa, $canal)) {
            // Menu que encaminha para equipe que ninguem esta olhando e pior que
            // dizer "estamos fechados".
            $this->saidas[] = $aviso;
            $conversa->update(['chatbot_id' => $bot->id, 'chatbot_estado' => self::CONCLUIDO]);
            $this->registrar($conversa, 'Bot respondeu fora do horário e encerrou');

            return true;
        }

        $conversa->update([
            'chatbot_id'         => $bot->id,
            'chatbot_estado'     => self::ATIVO,
            'chatbot_respostas'  => [],
            'chatbot_tentativas' => 0,
        ]);

        $this->executar($bot, $conversa, $primeiro, 0);

        return true;
    }

    // -------------------------------------------------------------- retomada

    private function retomar(Chatbot $bot, Conversation $conversa, Message $mensagem): bool
    {
        $texto = trim((string) $mensagem->corpo);
        $passo = $conversa->chatbotStep;

        if (! $passo) {
            return false;
        }

        // Escape a qualquer momento: o cliente nunca deve ficar preso no fluxo.
        if ($texto !== '' && $this->normalizar($texto) === $this->normalizar($bot->palavra_escape)) {
            return $this->entregar($bot, $conversa, null, 'Cliente pediu atendente', self::ESCAPOU);
        }

        $acao = $passo->actions()->where('ordem', $conversa->chatbot_acao_ordem)->first();

        if (! $acao) {
            return false;
        }

        return match ($conversa->chatbot_aguardando) {
            self::AGUARDA_MENU     => $this->responderMenu($bot, $conversa, $passo, $acao, $texto),
            self::AGUARDA_PERGUNTA => $this->responderPergunta($bot, $conversa, $passo, $acao, $texto),
            default                => false,
        };
    }

    private function responderMenu(
        Chatbot $bot,
        Conversation $conversa,
        ChatbotStep $passo,
        ChatbotAction $acao,
        string $texto,
    ): bool {
        $escolha = $this->acharOpcao($acao, $texto);

        if ($escolha === null) {
            return $this->naoEntendi($bot, $conversa, $passo, $acao);
        }

        $destino = $passo->destino(ChatbotEdge::opcao($escolha));

        if (! $destino) {
            // Opcao sem destino: a validacao impede publicar, mas se chegou aqui
            // nao deixa o cliente falando com parede.
            return $this->entregar($bot, $conversa, null, 'Opção sem destino no fluxo', self::ESCAPOU);
        }

        $rotulo = $this->rotuloOpcao($acao, $escolha) ?? $escolha;

        // A escolha do menu tambem preenche o cadastro: "1) Plano 300MB" deve virar o
        // campo Plano, e nao so um caminho no fluxo.
        $campoDoMenu = trim((string) $acao->cfg('campo_contato'));

        if ($campoDoMenu !== '' && $conversa->contact) {
            $gravou = app(CampoDoContato::class)->gravar($conversa->contact, $campoDoMenu, $rotulo);

            if ($gravou['ok']) {
                $marcador = CampoDoContato::marcador($campoDoMenu);

                if ($marcador !== '') {
                    $respostas = $conversa->chatbot_respostas ?? [];
                    $respostas[$marcador] = $rotulo;
                    $conversa->update(['chatbot_respostas' => $respostas]);
                }
            } else {
                // Aqui NAO se pede de novo, ao contrario da pergunta: o cliente
                // escolheu de uma lista NOSSA. Rotulo que nao cabe no campo e erro de
                // configuracao — e publicar ja avisa. Cobrar do cliente seria trocar
                // o culpado, e ele nao tem outra opcao para dar.
                $this->registrar($conversa, 'Não gravei a escolha no cadastro: o rótulo não serve para o campo.');
            }
        }

        $conversa->update(['chatbot_tentativas' => 0, 'chatbot_aguardando' => null]);
        $this->registrar($conversa, 'Cliente escolheu: '.$rotulo);

        $this->executar($bot, $conversa, $destino, 0);

        return true;
    }

    private function responderPergunta(
        Chatbot $bot,
        Conversation $conversa,
        ChatbotStep $passo,
        ChatbotAction $acao,
        string $texto,
    ): bool {
        if ($texto === '') {
            return $this->naoEntendi($bot, $conversa, $passo, $acao);
        }

        $campo = trim((string) $acao->cfg('campo_contato'));
        $contato = $conversa->contact;

        // A GRAVACAO VEM PRIMEIRO, antes de limpar a espera: resposta que nao serve
        // mantem a conversa aguardando a mesma pergunta. Se limpasse antes, o fluxo
        // seguiria com o campo vazio e ninguem saberia.
        if ($campo !== '' && $contato) {
            $r = app(CampoDoContato::class)->gravar($contato, $campo, $texto);

            if (! $r['ok'] && $r['erro'] !== null) {
                // Nao guarda errado e explica o que esta errado. CPF com digito
                // trocado no cadastro faz o provedor cobrar a pessoa errada, e a
                // mensagem generica de "nao entendi" nao ensina nada a quem esta
                // do outro lado.
                return $this->respostaInvalida($bot, $conversa, $r['erro']);
            }

            if ($r['ok']) {
                $this->registrar(
                    $conversa,
                    'Gravado em '.(CampoDoContato::rotulo($campo) ?? $campo).': '.\Illuminate\Support\Str::limit($texto, 40)
                );
            } else {
                // Campo apagado em Configuracoes depois de o fluxo ter sido montado.
                // Segue o atendimento: o cliente nao tem como consertar isso, e
                // travar aqui seria punir ele por configuracao nossa.
                $this->registrar($conversa, 'Não gravei a resposta: o campo escolhido no fluxo não existe mais.');
            }
        }

        $chave = trim((string) $acao->cfg('guardar_em'));

        // Sem apelido explicito, o marcador sai do proprio campo: quem escolheu
        // "CPF" pode escrever {{cpf}} na mensagem seguinte sem configurar nada.
        if ($chave === '' && $campo !== '') {
            $chave = CampoDoContato::marcador($campo);
        }

        if ($chave !== '') {
            $respostas = $conversa->chatbot_respostas ?? [];
            $respostas[$chave] = $texto;
            $conversa->update(['chatbot_respostas' => $respostas]);
        }

        $conversa->update(['chatbot_tentativas' => 0, 'chatbot_aguardando' => null]);
        $this->registrar($conversa, 'Cliente respondeu '.($chave ?: 'a pergunta').': '.\Illuminate\Support\Str::limit($texto, 60));

        // Continua no MESMO bloco, na acao seguinte.
        $this->executar($bot, $conversa->refresh(), $passo, $acao->ordem);

        return true;
    }

    public function retomarDepoisDaEspera(Conversation $conversa, int $stepId, int $daOrdem): void
    {
        if ($conversa->chatbot_estado !== self::ATIVO || $conversa->atendente_id) {
            // Enquanto o bot esperava, um humano pode ter assumido. Nesse caso o
            // bot nao volta a falar.
            return;
        }

        $bot = $conversa->chatbot;
        $passo = $conversa->chatbot_id ? ChatbotStep::find($stepId) : null;

        if (! $bot || ! $passo) {
            return;
        }

        $this->saidas = [];
        $this->canal = $conversa->channel;

        $this->executar($bot, $conversa, $passo, $daOrdem);
        $this->despachar($conversa);
    }

    // -------------------------------------------------------------- execucao

    /**
     * Roda as acoes do bloco em ordem e segue as arestas. Para quando uma acao
     * espera o cliente, quando encerra, ou quando o teto de blocos e atingido.
     */
    private function executar(Chatbot $bot, Conversation $conversa, ?ChatbotStep $passo, int $depoisDaOrdem): void
    {
        $blocos = 0;

        while ($passo && $blocos++ < self::MAX_BLOCOS) {
            $acoes = $passo->actions()->where('ordem', '>', $depoisDaOrdem)->orderBy('ordem')->get();
            $depoisDaOrdem = 0;
            $desviou = false;

            foreach ($acoes as $acao) {
                switch ($acao->tipo) {
                    case ChatbotAction::MENSAGEM:
                        $this->saidas[] = $this->comMarcadores($conversa, (string) $acao->cfg('texto'));
                        break;

                    case ChatbotAction::MENU:
                        $this->saidas[] = $this->textoDoMenu($bot, $acao, $conversa);
                        $conversa->update([
                            'chatbot_step_id'    => $passo->id,
                            'chatbot_acao_ordem' => $acao->ordem,
                            'chatbot_aguardando' => self::AGUARDA_MENU,
                        ]);

                        return;

                    case ChatbotAction::PERGUNTA:
                        $this->saidas[] = $this->comMarcadores($conversa, (string) $acao->cfg('texto'));
                        $conversa->update([
                            'chatbot_step_id'    => $passo->id,
                            'chatbot_acao_ordem' => $acao->ordem,
                            'chatbot_aguardando' => self::AGUARDA_PERGUNTA,
                        ]);

                        return;

                    case ChatbotAction::ESPERAR:
                        $conversa->update([
                            'chatbot_step_id'    => $passo->id,
                            'chatbot_acao_ordem' => $acao->ordem,
                            'chatbot_aguardando' => null,
                        ]);

                        // Esvazia ANTES de agendar: a continuacao e outro job, e o
                        // que ela disser tem que vir depois do que ja foi dito aqui.
                        $this->despachar($conversa);

                        ContinuarFluxo::dispatch($conversa->id, $passo->id, $acao->ordem)
                            ->delay(now()->addSeconds(max(1, (int) $acao->cfg('segundos', 1))));

                        return;

                    case ChatbotAction::ETIQUETA:
                        // Passa pelo Etiquetador, o mesmo caminho da mao do atendente
                        // e do futuro agente de IA, para o rastro de ORIGEM ser um so.
                        // Nao interrompe nem encerra: etiquetar e um efeito colateral.
                        $contato = $conversa->contact;

                        if ($contato) {
                            $etiquetador = app(Etiquetador::class);
                            $etiquetador->aplicar($contato, (array) $acao->cfg('adicionar', []), Etiquetador::CHATBOT);
                            $etiquetador->remover($contato, (array) $acao->cfg('remover', []));
                        }
                        break;

                    case ChatbotAction::CONDICIONAL:
                        $lado = $this->avaliar($conversa, $acao) ? ChatbotEdge::SIM : ChatbotEdge::NAO;
                        $proximo = $passo->destino($lado);

                        $this->registrar($conversa, 'Condição resolvida como "'.$lado.'"');

                        if (! $proximo) {
                            return;
                        }

                        $passo = $proximo;
                        $desviou = true;
                        break 2;

                    case ChatbotAction::TRANSFERIR:
                        $this->entregar(
                            $bot,
                            $conversa,
                            $acao,
                            'Fluxo encaminhou em "'.$passo->nome.'"',
                            self::CONCLUIDO,
                        );

                        return;

                    case ChatbotAction::CONCLUIR:
                        $aviso = trim((string) $acao->cfg('aviso'));

                        if ($aviso !== '') {
                            $this->saidas[] = $this->comMarcadores($conversa, $aviso);
                        }

                        $conversa->update([
                            'chatbot_estado'     => self::CONCLUIDO,
                            'chatbot_aguardando' => null,
                        ]);
                        $this->registrar($conversa, 'Fluxo concluiu o atendimento');

                        return;
                }
            }

            if ($desviou) {
                continue;
            }

            $passo = $passo->destino();
        }

        // Saiu do laco sem encerrar: o fluxo acabou (ou girou demais). Deixa a
        // conversa em Novos para uma pessoa, em vez de o cliente ficar sem resposta.
        if ($blocos >= self::MAX_BLOCOS) {
            $this->registrar($conversa, 'Fluxo interrompido: passou de '.self::MAX_BLOCOS.' blocos (possível ciclo)');
        }

        $conversa->update(['chatbot_aguardando' => null]);
    }

    // ----------------------------------------------------------------- apoio

    private function entregar(
        Chatbot $bot,
        Conversation $conversa,
        ?ChatbotAction $acao,
        string $trilha,
        string $estado,
    ): bool {
        $equipe = $acao ? \App\Models\Team::find($acao->cfg('team_id')) : null;

        $aviso = trim((string) ($acao?->cfg('aviso') ?? $bot->mensagem_transferindo ?? ''));

        if ($aviso === '') {
            $aviso = 'Um momento, já vou te encaminhar para um atendente.';
        }

        $this->saidas[] = $aviso;

        if ($equipe) {
            // transferir() devolve a conversa para Novos da equipe destino, sem
            // atendente, e registra o rastro. Reaproveitado inteiro.
            $conversa->transferir($equipe);
        }

        $conversa->update([
            'chatbot_estado'     => $equipe ? self::CONCLUIDO : $estado,
            'chatbot_aguardando' => null,
            'chatbot_tentativas' => 0,
        ]);

        $this->registrar($conversa, $trilha.($equipe ? " → {$equipe->nome}" : ''));

        return true;
    }

    /**
     * Resposta que nao passou na validacao do campo.
     *
     * Diferente do naoEntendi por dizer O QUE esta errado — "esse CPF nao parece
     * valido" ensina, "nao entendi" nao. Usa a MESMA valvula de escape: depois de
     * max_tentativas o cliente vai para uma pessoa, porque repetir a mesma pergunta
     * para sempre e o pior resultado possivel.
     */
    private function respostaInvalida(Chatbot $bot, Conversation $conversa, string $motivo): bool
    {
        $tentativas = $conversa->chatbot_tentativas + 1;
        $conversa->update(['chatbot_tentativas' => $tentativas]);

        if ($tentativas >= $bot->max_tentativas) {
            return $this->entregar($bot, $conversa, null, 'Resposta inválida repetida; encaminhado', self::ESCAPOU);
        }

        $this->saidas[] = $motivo;

        // chatbot_aguardando fica como esta: a conversa continua esperando a MESMA
        // pergunta, senao a proxima mensagem do cliente cairia no vazio.
        return true;
    }

    private function naoEntendi(Chatbot $bot, Conversation $conversa, ChatbotStep $passo, ChatbotAction $acao): bool
    {
        $tentativas = $conversa->chatbot_tentativas + 1;
        $conversa->update(['chatbot_tentativas' => $tentativas]);

        // Prender o cliente num robo que nao entende e o pior resultado possivel.
        if ($tentativas >= $bot->max_tentativas) {
            return $this->entregar($bot, $conversa, null, 'Bot não entendeu e encaminhou', self::ESCAPOU);
        }

        $texto = trim((string) $bot->mensagem_nao_entendi);

        $this->saidas[] = $acao->tipo === ChatbotAction::MENU
            ? trim($texto."\n\n".$this->textoDoMenu($bot, $acao, $conversa, semTexto: true))
            : $texto;

        return true;
    }

    private function textoDoMenu(Chatbot $bot, ChatbotAction $acao, Conversation $conversa, bool $semTexto = false): string
    {
        $partes = [];

        if (! $semTexto) {
            $texto = trim((string) $acao->cfg('texto'));

            if ($texto !== '') {
                $partes[] = $this->comMarcadores($conversa, $texto);
            }
        }

        $linhas = collect($acao->cfg('opcoes', []))
            ->map(fn ($o) => trim((string) ($o['gatilho'] ?? '')).' - '.trim((string) ($o['rotulo'] ?? '')))
            ->all();

        if ($linhas !== []) {
            $partes[] = implode("\n", $linhas);
        }

        $partes[] = 'Ou digite *'.$bot->palavra_escape.'* para falar com uma pessoa.';

        return implode("\n\n", $partes);
    }

    private function acharOpcao(ChatbotAction $acao, string $texto): ?string
    {
        $alvo = $this->normalizar($texto);

        if ($alvo === '') {
            return null;
        }

        foreach ($acao->cfg('opcoes', []) as $opcao) {
            $gatilho = trim((string) ($opcao['gatilho'] ?? ''));

            if ($this->normalizar($gatilho) === $alvo
                || $this->normalizar((string) ($opcao['rotulo'] ?? '')) === $alvo) {
                return $gatilho;
            }
        }

        return null;
    }

    private function rotuloOpcao(ChatbotAction $acao, string $gatilho): ?string
    {
        foreach ($acao->cfg('opcoes', []) as $opcao) {
            if (trim((string) ($opcao['gatilho'] ?? '')) === $gatilho) {
                return $opcao['rotulo'] ?? null;
            }
        }

        return null;
    }

    private function avaliar(Conversation $conversa, ChatbotAction $acao): bool
    {
        $guardado = $this->normalizar((string) data_get($conversa->chatbot_respostas ?? [], (string) $acao->cfg('campo')));
        $valor = $this->normalizar((string) $acao->cfg('valor'));

        if ($valor === '') {
            return false;
        }

        return match ($acao->cfg('operador', 'contem')) {
            'igual'  => $guardado === $valor,
            'comeca' => str_starts_with($guardado, $valor),
            default  => str_contains($guardado, $valor),
        };
    }

    /** Deixa o fluxo citar o nome do contato e o que ele respondeu antes. */
    private function comMarcadores(Conversation $conversa, string $texto): string
    {
        $trocas = ['{{nome}}' => $conversa->contact?->nomeExibicao() ?? ''];

        foreach ($conversa->chatbot_respostas ?? [] as $chave => $valor) {
            $trocas['{{'.$chave.'}}'] = (string) $valor;
        }

        return strtr($texto, $trocas);
    }

    /**
     * Bus::chain e nao dispatch solto: a corrente garante que a segunda mensagem
     * so e despachada quando a primeira concluiu. WithoutOverlapping impede envio
     * simultaneo, mas job que nao pega o lock volta para o fim da fila — com dez
     * workers, o menu poderia chegar antes do "ola".
     */
    private function despachar(Conversation $conversa): void
    {
        $canal = $this->canal;

        if ($this->saidas === [] || ! $canal) {
            return;
        }

        $jobs = [];

        foreach ($this->saidas as $texto) {
            $texto = trim($texto);

            if ($texto === '') {
                continue;
            }

            $mensagem = Message::create([
                'conversation_id' => $conversa->id,
                'channel_id'      => $canal->id,
                'direcao'         => 'out',
                // A marca que faz o resto funcionar: nao tira a conversa de Novos,
                // aparece marcada na tela, nao conta como resposta de atendente.
                'automatica'      => true,
                'tipo'            => 'text',
                'corpo'           => $texto,
                'status'          => Message::STATUS_QUEUED,
            ]);

            $jobs[] = new SendTextMessage($mensagem->id);
        }

        $this->saidas = [];

        if ($jobs !== []) {
            Bus::chain($jobs)->dispatch();
        }
    }

    private function registrar(Conversation $conversa, string $descricao): void
    {
        ConversationEvent::create([
            'conversation_id' => $conversa->id,
            'tipo'            => ConversationEvent::CHATBOT,
            'descricao'       => $descricao,
        ]);
    }

    private function foraDoHorario(Conversation $conversa, Channel $canal): bool
    {
        $conta = Tenant::find($canal->tenant_id);

        if (! $conta) {
            return false;
        }

        $horas = new BusinessHours($conta);
        $equipe = $conversa->team;

        return $horas->configurado($canal, $equipe) && ! $horas->abertoAgora($canal, $equipe);
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ò' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }
}
