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

    /**
     * Acrescenta uma acao ao grupo, SEMPRE antes de um encerrador.
     *
     * Transferir e Concluir terminam o fluxo: nada roda depois deles. Deixar a acao
     * nova cair no fim de um grupo que ja transfere criava uma acao MORTA — aparecia
     * no cartao, o usuario configurava, e ela nunca executava. Aconteceu de verdade
     * com uma etiqueta que nunca chegou ao contato. Em vez de permitir e avisar
     * depois, o encerrador continua sendo o ultimo.
     */
    public function adicionarAcao(ChatbotStep $passo, string $tipo, array $config = []): ChatbotAction
    {
        $primeiroEncerrador = $passo->actions()
            ->whereIn('tipo', ChatbotAction::ENCERRAM)
            ->orderBy('ordem')
            ->first();

        if ($primeiroEncerrador) {
            $ordem = (int) $primeiroEncerrador->ordem;

            // Abre espaco empurrando o encerrador (e o que vier depois) para baixo.
            $passo->actions()->where('ordem', '>=', $ordem)->increment('ordem');
        } else {
            $ordem = (int) $passo->actions()->max('ordem') + 1;
        }

        return ChatbotAction::create([
            'chatbot_id' => $passo->chatbot_id,
            'step_id'    => $passo->id,
            'ordem'      => $ordem,
            'tipo'       => $tipo,
            'config'     => $config,
        ]);
    }

    /**
     * Reordena o grupo deixando os encerradores no fim, mantendo o resto na mesma
     * ordem relativa. Serve para reparar fluxo montado antes desta regra.
     */
    public function arrumarOrdem(ChatbotStep $passo): void
    {
        $acoes = $passo->actions()->orderBy('ordem')->orderBy('id')->get();

        // sortBy e estavel no PHP 8: quem nao encerra mantem a ordem que tinha.
        $ordenadas = $acoes->sortBy(fn (ChatbotAction $a) => $a->encerra() ? 1 : 0)->values();

        foreach ($ordenadas as $i => $acao) {
            if ((int) $acao->ordem !== $i + 1) {
                $acao->update(['ordem' => $i + 1]);
            }
        }
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

            // Rede de seguranca: adicionarAcao ja impede criar isso, e a migracao
            // reparou o que existia. Se aparecer de novo, e melhor barrar do que
            // deixar o usuario configurar uma acao que nunca roda.
            $encerrouEm = null;

            foreach ($passo->actions->sortBy('ordem') as $acao) {
                if ($encerrouEm !== null) {
                    $problemas[] = "\"{$passo->nome}\" → {$acao->rotulo()} vem depois de \"{$encerrouEm}\" e nunca vai rodar.";
                    break;
                }

                if ($acao->encerra()) {
                    $encerrouEm = $acao->rotulo();
                }
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

                if ($acao->tipo === ChatbotAction::PERGUNTA) {
                    $campo = trim((string) $acao->cfg('campo_contato'));

                    // Um dos dois basta: guardar no cadastro ja da destino a
                    // resposta, e nem toda pergunta precisa de apelido.
                    if ($campo === '' && trim((string) $acao->cfg('guardar_em')) === '') {
                        $problemas[] = "{$onde}: falta dizer onde guardar a resposta.";
                    }

                    // Campo apagado em Configuracoes depois de o fluxo ser montado:
                    // em producao a resposta seria descartada em silencio.
                    if ($campo !== '' && ! \App\Services\CampoDoContato::existe($campo)) {
                        $problemas[] = "{$onde}: o campo escolhido para guardar a resposta não existe mais.";
                    }
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

                // Menu que preenche campo do cadastro.
                $campoDoMenu = trim((string) $acao->cfg('campo_contato'));

                if ($campoDoMenu !== '') {
                    if (! \App\Services\CampoDoContato::existe($campoDoMenu)) {
                        $problemas[] = "{$onde}: o campo escolhido para guardar a escolha não existe mais.";
                    } else {
                        // Rotulo que nao cabe no campo seria descartado em silencio em
                        // producao — e a escolha do cliente e valida, ele nao tem como
                        // saber nem consertar.
                        $ruins = app(\App\Services\CampoDoContato::class)
                            ->naoCabem($campoDoMenu, $opcoes->pluck('rotulo')->all());

                        foreach ($ruins as $ruim) {
                            $problemas[] = "{$onde}: a opção \"{$ruim}\" não serve para o campo escolhido.";
                        }
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
     * Fluxo de exemplo pronto para editar. Canvas vazio nao ensina nada; canvas
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
