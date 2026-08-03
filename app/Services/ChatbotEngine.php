<?php

namespace App\Services;

use App\Jobs\SendTextMessage;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\ChatbotNode;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Message;
use App\Models\Tenant;

// O bot atende o primeiro contato e encaminha para a equipe certa.
//
// Regra que atravessa o arquivo inteiro: UMA mensagem de saida por mensagem
// recebida. Texto e menu vao na mesma bolha. Se o bot enfileirasse duas
// mensagens, dois jobs na fila nao tem ordem garantida e o cliente poderia
// receber o menu antes da explicacao.
class ChatbotEngine
{
    public const ATIVO = 'ativo';

    public const CONCLUIDO = 'concluido';

    public const ESCAPOU = 'escapou';

    // O que o cliente digita para voltar. Se alguem configurar um gatilho '0', o
    // dele vence e o menu deixa de oferecer voltar — melhor que prometer e nao
    // cumprir.
    private const GATILHO_VOLTAR = '0';

    // Sinalizador interno de "voltar", devolvido por escolher(). Nao pode ser '0':
    // '0' e FALSY em PHP, e um teste como `if (! $escolha)` mandaria o cliente
    // para "nao entendi" em vez de subir um nivel. Foi exatamente o bug que o
    // teste do voltar pegou.
    private const VOLTAR = 'voltar';

    /**
     * Devolve true se o bot cuidou desta mensagem. Quem chama usa isso para NAO
     * disparar a resposta automatica de fora do horario: duas mensagens de robo
     * seguidas e a pior experiencia possivel.
     */
    public function talvezAtender(Channel $canal, Message $mensagem): bool
    {
        $conversa = $mensagem->conversation;

        if (! $conversa || ! $this->podeAtender($conversa, $mensagem)) {
            return false;
        }

        $bot = Chatbot::ativoPara($canal);

        if (! $bot) {
            return false;
        }

        // Primeiro contato desta conversa com o bot.
        if ($conversa->chatbot_estado === null) {
            return $this->abrir($bot, $conversa, $canal);
        }

        return $this->responder($bot, $conversa, $mensagem);
    }

    /**
     * O texto exato que o cliente recebe naquele ponto da arvore. Quem monta o
     * fluxo precisa VER o resultado; sem isso a unica forma de conferir e mandar
     * mensagem de um telefone de verdade.
     */
    public function previa(Chatbot $bot, ?ChatbotNode $atual = null): string
    {
        return $this->comMenu(
            $bot,
            $atual,
            $atual ? $atual->mensagem : $bot->mensagem_boas_vindas,
        );
    }

    // ------------------------------------------------------------------- travas

    private function podeAtender(Conversation $conversa, Message $mensagem): bool
    {
        // Mensagem nossa, ou automatica, nunca alimenta o bot: seria o bot
        // conversando com ele mesmo.
        if ($mensagem->automatica || $mensagem->direcao !== 'in') {
            return false;
        }

        // Grupo nunca. Bairro com 40 mensagens a noite viraria 40 menus na frente
        // de todos os clientes daquele bairro. Mesma razao da resposta automatica.
        if ($conversa->contact?->eGrupo()) {
            return false;
        }

        // Humano assumiu: o bot sai de cena e nao volta.
        if ($conversa->atendente_id) {
            return false;
        }

        if (in_array($conversa->chatbot_estado, [self::CONCLUIDO, self::ESCAPOU], true)) {
            return false;
        }

        // Alguem da equipe ja escreveu nesta conversa. Mesmo sem assumir
        // formalmente, quem respondeu tomou a conversa para si.
        $humanoJaFalou = $conversa->messages()
            ->where('direcao', 'out')
            ->where('automatica', false)
            ->exists();

        return ! $humanoJaFalou;
    }

    // ------------------------------------------------------------------ abertura

    private function abrir(Chatbot $bot, Conversation $conversa, Channel $canal): bool
    {
        $foraDoHorario = $this->foraDoHorario($conversa, $canal);
        $aviso = trim((string) $bot->mensagem_fora_horario);

        // Menu que encaminha para uma equipe que ninguem esta olhando e pior que
        // dizer "estamos fechados". Mas so quando o texto existe: sem ele, o bot
        // atende 24h, que e o proposito de ter um bot.
        if ($foraDoHorario && $aviso !== '') {
            $this->enviar($conversa, $canal, $aviso);
            $this->registrar($conversa, 'Bot respondeu fora do horário e encerrou');

            $conversa->update([
                'chatbot_id'     => $bot->id,
                'chatbot_estado' => self::CONCLUIDO,
            ]);

            return true;
        }

        $conversa->update([
            'chatbot_id'         => $bot->id,
            'chatbot_node_id'    => null,
            'chatbot_tentativas' => 0,
            'chatbot_estado'     => self::ATIVO,
        ]);

        $this->enviar($conversa, $canal, $this->comMenu($bot, null, $bot->mensagem_boas_vindas));

        return true;
    }

    // ----------------------------------------------------------------- resposta

    private function responder(Chatbot $bot, Conversation $conversa, Message $mensagem): bool
    {
        $canal = $conversa->channel;

        if (! $canal) {
            return false;
        }

        $texto = $this->normalizar((string) $mensagem->corpo);
        $atual = $conversa->chatbotNode;

        // Escape a qualquer momento: o cliente nunca deve ficar preso no menu.
        if ($texto !== '' && $texto === $this->normalizar($bot->palavra_escape)) {
            return $this->entregarAoHumano($bot, $conversa, $canal, null, 'Cliente pediu atendente');
        }

        $escolha = $this->escolher($bot, $atual, $texto);

        // === null, e nao `! $escolha`: qualquer sinalizador ou gatilho falsy
        // ('0', '') seria confundido com "nao escolheu nada".
        if ($escolha === null) {
            return $this->naoEntendi($bot, $conversa, $canal, $atual);
        }

        // 'voltar' nao e um nó: e a subida de um nível.
        if ($escolha === self::VOLTAR) {
            $pai = $atual?->parent;

            $conversa->update([
                'chatbot_node_id'    => $pai?->id,
                'chatbot_tentativas' => 0,
            ]);

            $this->enviar($conversa, $canal, $this->comMenu($bot, $pai, null));

            return true;
        }

        $conversa->update(['chatbot_tentativas' => 0]);

        return match ($escolha->tipo) {
            ChatbotNode::MENU => $this->abrirMenu($bot, $conversa, $canal, $escolha),
            ChatbotNode::EQUIPE => $this->entregarAoHumano(
                $bot,
                $conversa,
                $canal,
                $escolha,
                'Cliente escolheu: '.$this->caminho($escolha),
            ),
            default => $this->responderTexto($bot, $conversa, $canal, $escolha, $atual),
        };
    }

    private function abrirMenu(Chatbot $bot, Conversation $conversa, Channel $canal, ChatbotNode $node): bool
    {
        $conversa->update(['chatbot_node_id' => $node->id]);
        $this->enviar($conversa, $canal, $this->comMenu($bot, $node, $node->mensagem));

        return true;
    }

    // Texto e menu na MESMA mensagem, e o cliente permanece onde estava.
    private function responderTexto(
        Chatbot $bot,
        Conversation $conversa,
        Channel $canal,
        ChatbotNode $node,
        ?ChatbotNode $atual,
    ): bool {
        $this->enviar($conversa, $canal, $this->comMenu($bot, $atual, $node->mensagem));
        $this->registrar($conversa, 'Bot informou: '.$this->caminho($node));

        return true;
    }

    private function entregarAoHumano(
        Chatbot $bot,
        Conversation $conversa,
        Channel $canal,
        ?ChatbotNode $node,
        string $trilha,
    ): bool {
        $equipe = $node?->team;

        // ?? e nao ?:, pela mesma razao: mensagem que seja literalmente "0" e
        // falsy e seria descartada em silencio.
        $aviso = trim((string) ($node?->mensagem ?? $bot->mensagem_transferindo ?? ''));

        if ($aviso === '') {
            $aviso = 'Um momento, já vou te encaminhar para um atendente.';
        }

        $this->enviar($conversa, $canal, $aviso);

        if ($equipe) {
            // transferir() ja devolve a conversa para Novos da equipe destino, sem
            // atendente, e registra o rastro. Reaproveitado inteiro.
            $conversa->transferir($equipe);
        }

        $conversa->update([
            'chatbot_estado'     => $node?->team ? self::CONCLUIDO : self::ESCAPOU,
            'chatbot_tentativas' => 0,
        ]);

        $this->registrar($conversa, $trilha.($equipe ? " → {$equipe->nome}" : ''));

        return true;
    }

    private function naoEntendi(Chatbot $bot, Conversation $conversa, Channel $canal, ?ChatbotNode $atual): bool
    {
        $tentativas = $conversa->chatbot_tentativas + 1;

        // Insistir para sempre e prender o cliente num robô. Depois do limite,
        // pessoa.
        if ($tentativas >= $bot->max_tentativas) {
            $conversa->update(['chatbot_tentativas' => $tentativas]);

            return $this->entregarAoHumano($bot, $conversa, $canal, null, 'Bot não entendeu e encaminhou para atendente');
        }

        $conversa->update(['chatbot_tentativas' => $tentativas]);
        $this->enviar($conversa, $canal, $this->comMenu($bot, $atual, $bot->mensagem_nao_entendi));

        return true;
    }

    // -------------------------------------------------------------------- apoio

    /** @return ChatbotNode|string|null nó escolhido, self::VOLTAR, ou null */
    private function escolher(Chatbot $bot, ?ChatbotNode $atual, string $texto)
    {
        if ($texto === '') {
            return null;
        }

        foreach ($this->opcoes($bot, $atual) as $opcao) {
            if ($this->normalizar($opcao->gatilho) === $texto
                || $this->normalizar($opcao->rotulo) === $texto) {
                return $opcao;
            }
        }

        if ($atual && $texto === self::GATILHO_VOLTAR) {
            return self::VOLTAR;
        }

        return null;
    }

    /** @return \Illuminate\Support\Collection<int, ChatbotNode> */
    private function opcoes(Chatbot $bot, ?ChatbotNode $atual)
    {
        return $atual
            ? $atual->children()->get()
            : $bot->raiz()->get();
    }

    private function comMenu(Chatbot $bot, ?ChatbotNode $atual, ?string $prefixo): string
    {
        $partes = [];
        $prefixo = trim((string) $prefixo);

        if ($prefixo !== '') {
            $partes[] = $prefixo;
        }

        $opcoes = $this->opcoes($bot, $atual);

        if ($opcoes->isNotEmpty()) {
            $linhas = $opcoes->map(fn (ChatbotNode $o) => "{$o->gatilho} - {$o->rotulo}")->all();

            // So oferece voltar se ninguem configurou o gatilho 0: prometer uma
            // opcao que nao funciona e pior que nao ter a opcao.
            if ($atual && ! $opcoes->contains(fn (ChatbotNode $o) => trim($o->gatilho) === self::GATILHO_VOLTAR)) {
                $linhas[] = self::GATILHO_VOLTAR.' - Voltar';
            }

            $partes[] = implode("\n", $linhas);
        }

        $partes[] = 'Ou digite *'.$bot->palavra_escape.'* para falar com uma pessoa.';

        return implode("\n\n", $partes);
    }

    private function caminho(ChatbotNode $node): string
    {
        $nomes = [$node->rotulo];
        $pai = $node->parent;

        // Teto de 10 por seguranca: arvore com ciclo travaria o job.
        for ($i = 0; $pai && $i < 10; $i++) {
            $nomes[] = $pai->rotulo;
            $pai = $pai->parent;
        }

        return implode(' > ', array_reverse($nomes));
    }

    private function enviar(Conversation $conversa, Channel $canal, string $texto): void
    {
        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $canal->id,
            'direcao'         => 'out',
            // A marca que faz todo o resto funcionar: nao tira a conversa de Novos,
            // aparece marcada na tela e nao conta como resposta de atendente.
            'automatica'      => true,
            'tipo'            => 'text',
            'corpo'           => $texto,
            'status'          => Message::STATUS_QUEUED,
        ]);

        SendTextMessage::dispatch($mensagem->id);
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

        return $horas->configurado($canal, $equipe)
            && ! $horas->abertoAgora($canal, $equipe);
    }

    // Cliente digita "Suporte", "suporte", "SUPORTE" e "Suporte " — tudo isso e a
    // mesma escolha.
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
