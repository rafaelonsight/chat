<?php

namespace App\Services;

use App\Models\Chatbot;
use App\Models\ChatbotAction;
use App\Models\ChatbotEdge;
use App\Models\ChatbotStep;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

// Monta e edita a estrutura do fluxo. Fica fora do componente de tela porque a
// integridade do grafo nao pode depender de quem chama: o editor visual, um
// import futuro e o teste usam o mesmo caminho.
class ChatbotFluxo
{
    /** Passo de entrada, criado sob demanda: fluxo sem inicio nao roda. */
    public function garantirInicio(Chatbot $bot): ChatbotStep
    {
        $inicio = $bot->inicio();

        if ($inicio) {
            return $inicio;
        }

        return ChatbotStep::create([
            'chatbot_id' => $bot->id,
            'nome'       => 'Início do atendimento',
            'tipo'       => ChatbotStep::INICIO,
            'x'          => 80,
            'y'          => 240,
        ]);
    }

    public function criarPasso(Chatbot $bot, int $x, int $y, string $nome = 'Novo grupo'): ChatbotStep
    {
        return ChatbotStep::create([
            'chatbot_id' => $bot->id,
            'nome'       => $nome,
            'tipo'       => ChatbotStep::GRUPO,
            'x'          => $x,
            'y'          => $y,
        ]);
    }

    public function adicionarAcao(ChatbotStep $passo, string $tipo, array $config = []): ChatbotAction
    {
        return ChatbotAction::create([
            'chatbot_id' => $passo->chatbot_id,
            'step_id'    => $passo->id,
            'ordem'      => (int) $passo->actions()->max('ordem') + 1,
            'tipo'       => $tipo,
            'config'     => $config,
        ]);
    }

    /**
     * Liga dois passos. Uma saida leva a um destino so, entao ligar de novo no
     * mesmo handle SUBSTITUI — o usuario arrastando uma linha nova claramente quer
     * trocar o destino, nao criar ambiguidade que o banco recusaria.
     */
    public function ligar(ChatbotStep $de, ChatbotStep $para, string $handle = ChatbotEdge::SAIDA): ?ChatbotEdge
    {
        if ($de->id === $para->id) {
            return null;
        }

        if ($de->chatbot_id !== $para->chatbot_id) {
            return null;
        }

        return DB::transaction(function () use ($de, $para, $handle) {
            ChatbotEdge::where('from_step_id', $de->id)->where('from_handle', $handle)->delete();

            return ChatbotEdge::create([
                'chatbot_id'   => $de->chatbot_id,
                'from_step_id' => $de->id,
                'from_handle'  => $handle,
                'to_step_id'   => $para->id,
            ]);
        });
    }

    public function reordenarAcoes(ChatbotStep $passo, array $ids): void
    {
        foreach (array_values($ids) as $i => $id) {
            $passo->actions()->whereKey($id)->update(['ordem' => $i + 1]);
        }
    }

    /**
     * Publicar e um ato explicito: enquanto e rascunho, mexer no fluxo nao afeta
     * quem esta conversando agora.
     */
    public function publicar(Chatbot $bot): array
    {
        $problemas = $this->validar($bot);

        if ($problemas !== []) {
            return $problemas;
        }

        $bot->update([
            'status'       => Chatbot::PUBLICADO,
            'publicado_em' => now(),
        ]);

        // increment() faz "versao = versao + 1" no SQL. Calcular em PHP dependeria
        // do atributo estar hidratado e erraria com dois publicares simultaneos.
        $bot->increment('versao');

        return [];
    }

    /**
     * O que impede um fluxo de funcionar. Publicar um fluxo quebrado significa
     * cliente conversando com um robô que trava — pior que não ter bot.
     *
     * @return array<int, string>
     */
    public function validar(Chatbot $bot): array
    {
        $problemas = [];

        $inicio = $bot->inicio();

        if (! $inicio) {
            $problemas[] = 'O fluxo não tem passo de início.';

            return $problemas;
        }

        if (! $inicio->destino()) {
            $problemas[] = 'O início não está ligado a nenhum grupo.';
        }

        foreach ($bot->steps()->with('actions')->get() as $passo) {
            if ($passo->ehInicio()) {
                continue;
            }

            if ($passo->actions->isEmpty()) {
                $problemas[] = "O grupo \"{$passo->nome}\" não tem nenhuma ação.";

                continue;
            }

            foreach ($passo->actions as $acao) {
                $problemas = array_merge($problemas, $this->validarAcao($passo, $acao));
            }
        }

        return array_values(array_unique($problemas));
    }

    /** @return array<int, string> */
    private function validarAcao(ChatbotStep $passo, ChatbotAction $acao): array
    {
        $problemas = [];
        $onde = "\"{$passo->nome}\" → {$acao->rotulo()}";

        switch ($acao->tipo) {
            case ChatbotAction::MENSAGEM:
            case ChatbotAction::PERGUNTA:
                if (trim((string) $acao->cfg('texto')) === '') {
                    $problemas[] = "{$onde}: falta o texto.";
                }

                if ($acao->tipo === ChatbotAction::PERGUNTA && trim((string) $acao->cfg('guardar_em')) === '') {
                    $problemas[] = "{$onde}: falta dizer onde guardar a resposta.";
                }
                break;

            case ChatbotAction::MENU:
                $opcoes = collect($acao->cfg('opcoes', []));

                if ($opcoes->isEmpty()) {
                    $problemas[] = "{$onde}: menu sem opções.";
                    break;
                }

                if (trim((string) $acao->cfg('texto')) === '') {
                    $problemas[] = "{$onde}: falta o texto do menu.";
                }

                // Duas opcoes com o mesmo gatilho: nao ha resposta para qual
                // atende quando o cliente digitar.
                $gatilhos = $opcoes->pluck('gatilho')->map(fn ($g) => trim((string) $g));

                if ($gatilhos->duplicates()->isNotEmpty()) {
                    $problemas[] = "{$onde}: há opções repetidas.";
                }

                // Opcao sem destino e um beco sem saida: o cliente escolhe e o bot
                // nao tem para onde levar.
                foreach ($opcoes as $opcao) {
                    $handle = ChatbotEdge::opcao(trim((string) ($opcao['gatilho'] ?? '')));

                    if (! $passo->destino($handle)) {
                        $rotulo = $opcao['rotulo'] ?? $opcao['gatilho'] ?? '?';
                        $problemas[] = "{$onde}: a opção \"{$rotulo}\" não leva a nenhum grupo.";
                    }
                }
                break;

            case ChatbotAction::CONDICIONAL:
                foreach ([ChatbotEdge::SIM, ChatbotEdge::NAO] as $lado) {
                    if (! $passo->destino($lado)) {
                        $problemas[] = "{$onde}: falta ligar o caminho \"{$lado}\".";
                    }
                }
                break;

            case ChatbotAction::ESPERAR:
                if ((int) $acao->cfg('segundos', 0) <= 0) {
                    $problemas[] = "{$onde}: a espera precisa ser maior que zero.";
                }
                break;
        }

        return $problemas;
    }

    /**
     * Fluxo de provedor pronto para editar. Canvas vazio nao ensina nada; canvas
     * com um fluxo plausivel ensina o modelo inteiro de uma vez.
     */
    public function criarExemplo(Chatbot $bot): void
    {
        $inicio = $this->garantirInicio($bot);

        $recepcao = $this->criarPasso($bot, 420, 200, 'Recepção');
        $this->adicionarAcao($recepcao, ChatbotAction::MENSAGEM, [
            'texto' => 'Olá! Sou o atendimento automático.',
        ]);
        $this->adicionarAcao($recepcao, ChatbotAction::MENU, [
            'texto'  => 'Como podemos ajudar?',
            'opcoes' => [
                ['gatilho' => '1', 'rotulo' => 'Financeiro'],
                ['gatilho' => '2', 'rotulo' => 'Suporte técnico'],
            ],
        ]);
        $this->ligar($inicio, $recepcao);

        $financeiro = $this->criarPasso($bot, 800, 60, 'Financeiro');
        $this->adicionarAcao($financeiro, ChatbotAction::TRANSFERIR, [
            'team_id' => Team::where('nome', 'ilike', 'financ%')->value('id'),
            'aviso'   => 'Vou te encaminhar para o Financeiro.',
        ]);
        $this->ligar($recepcao, $financeiro, ChatbotEdge::opcao('1'));

        $suporte = $this->criarPasso($bot, 800, 320, 'Suporte');
        $this->adicionarAcao($suporte, ChatbotAction::PERGUNTA, [
            'texto'      => 'Descreva rapidamente o problema.',
            'guardar_em' => 'problema',
        ]);
        $this->adicionarAcao($suporte, ChatbotAction::TRANSFERIR, [
            'team_id' => Team::where('nome', 'ilike', 'suporte%')->value('id'),
            'aviso'   => 'Obrigado. Vou te encaminhar para o Suporte.',
        ]);
        $this->ligar($recepcao, $suporte, ChatbotEdge::opcao('2'));
    }
}
