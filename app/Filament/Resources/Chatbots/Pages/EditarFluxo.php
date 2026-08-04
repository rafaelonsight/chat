<?php

namespace App\Filament\Resources\Chatbots\Pages;

use App\Filament\Resources\Chatbots\ChatbotResource;
use App\Models\Chatbot;
use App\Models\ChatbotAction;
use App\Models\ChatbotEdge;
use App\Models\ChatbotStep;
use App\Models\Team;
use App\Services\ChatbotFluxo;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * Construtor visual do fluxo. Cada acao persiste na hora — nao existe "salvar
 * tudo": o usuario arrasta um cartao e o desenho dele fica guardado. O que precisa
 * de ato explicito e PUBLICAR, porque e isso que passa a valer para o cliente.
 */
class EditarFluxo extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ChatbotResource::class;

    protected string $view = 'filament.resources.chatbots.pages.editar-fluxo';

    protected static ?string $title = 'Fluxo';

    /** Passo aberto na gaveta lateral. */
    public ?int $passoAberto = null;

    /** Acao sendo configurada. */
    public ?int $acaoAberta = null;

    /** Config em edicao, espelhada da acao aberta. */
    public array $form = [];

    public bool $paletaAberta = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Fluxo sem inicio nao roda; criar sob demanda evita que o usuario precise
        // saber que existe um passo especial.
        app(ChatbotFluxo::class)->garantirInicio($this->record);

        $this->trazerParaDentro();
    }

    /**
     * Bloco em coordenada negativa fica fora da area visivel, sem jeito de clicar.
     * Ja aconteceu em producao; aqui o desenho inteiro volta para dentro,
     * preservando as posicoes relativas.
     */
    private function trazerParaDentro(): void
    {
        $passos = $this->record->steps()->get();

        if ($passos->isEmpty()) {
            return;
        }

        $menorX = (int) $passos->min('x');
        $menorY = (int) $passos->min('y');

        if ($menorX >= 0 && $menorY >= 0) {
            return;
        }

        $dx = $menorX < 0 ? 40 - $menorX : 0;
        $dy = $menorY < 0 ? 40 - $menorY : 0;

        foreach ($passos as $passo) {
            $passo->update(['x' => $passo->x + $dx, 'y' => $passo->y + $dy]);
        }
    }

    public function getTitle(): string
    {
        return $this->record->nome;
    }

    public function getBreadcrumb(): string
    {
        return 'Fluxo';
    }

    private function fluxo(): ChatbotFluxo
    {
        return app(ChatbotFluxo::class);
    }

    // ------------------------------------------------------------------- leitura

    // #[Computed] e nao getXProperty: a magica do getXProperty saiu no Livewire 3.
    // Sem o atributo, \$this->passos na view seria simplesmente nulo.
    #[Computed]
    public function passos()
    {
        return $this->record->steps()->with(['actions', 'saidas'])->orderBy('id')->get();
    }

    #[Computed]
    public function arestas()
    {
        return $this->record->edges()->get();
    }

    #[Computed]
    public function problemas(): array
    {
        return $this->fluxo()->validar($this->record);
    }

    /**
     * Catalogo de campos para a pergunta guardar a resposta. Sai do banco a cada
     * render de proposito: campo personalizado criado agora precisa aparecer no
     * fluxo agora.
     */
    #[Computed]
    public function camposDoContato(): array
    {
        return \App\Services\CampoDoContato::agrupadas();
    }

    #[Computed]
    public function equipes()
    {
        return Team::ativas()->orderBy('nome')->pluck('nome', 'id')->all();
    }

    /**
     * O desenho que a tela precisa: um vetor por cartao, com as saidas ja
     * resolvidas. Montar isto aqui evita o blade fazer consulta dentro de laco.
     */
    #[Computed]
    public function desenho(): array
    {
        $arestas = $this->arestas->groupBy('from_step_id');

        return $this->passos->map(function (ChatbotStep $passo) use ($arestas) {
            $acoes = $passo->actions->map(fn (ChatbotAction $a) => [
                'id'     => $a->id,
                'tipo'   => $a->tipo,
                'rotulo' => $a->rotulo(),
                'resumo' => Str::limit($a->resumo(), 60),
            ])->values()->all();

            // Handles: um por opcao de menu / lado do condicional, ou a saida unica.
            $handles = collect($passo->actions)
                ->flatMap(fn (ChatbotAction $a) => collect($a->handles())->map(fn ($h) => [
                    'handle' => $h,
                    'rotulo' => $this->rotuloDoHandle($a, $h),
                ]))
                ->values()
                ->all();

            if ($handles === [] && ! $this->encerra($passo)) {
                $handles = [['handle' => ChatbotEdge::SAIDA, 'rotulo' => null]];
            }

            $saidas = ($arestas[$passo->id] ?? collect())
                ->mapWithKeys(fn (ChatbotEdge $e) => [$e->from_handle => $e->to_step_id])
                ->all();

            return [
                'id'      => $passo->id,
                'nome'    => $passo->nome,
                'inicio'  => $passo->ehInicio(),
                'x'       => $passo->x,
                'y'       => $passo->y,
                'acoes'   => $acoes,
                'handles' => $handles,
                'saidas'  => $saidas,
            ];
        })->values()->all();
    }

    private function encerra(ChatbotStep $passo): bool
    {
        return $passo->actions->contains(fn (ChatbotAction $a) => $a->encerra());
    }

    private function rotuloDoHandle(ChatbotAction $acao, string $handle): ?string
    {
        if ($acao->tipo === ChatbotAction::CONDICIONAL) {
            return $handle === ChatbotEdge::SIM ? 'sim' : 'não';
        }

        $gatilho = str_starts_with($handle, 'opcao:') ? substr($handle, 6) : $handle;

        foreach ($acao->cfg('opcoes', []) as $opcao) {
            if (trim((string) ($opcao['gatilho'] ?? '')) === $gatilho) {
                return $opcao['rotulo'] ?? $gatilho;
            }
        }

        return $gatilho;
    }

    // ------------------------------------------------------------------- escrita

    public function criarPasso(int $x = 400, int $y = 200): void
    {
        $this->estruturaMudou();

        $passo = $this->fluxo()->criarPasso($this->record, $x, $y);
        $this->passoAberto = $passo->id;
        $this->paletaAberta = true;
    }

    /**
     * Sem re-render de proposito: a posicao ja esta correta na tela porque o Alpine
     * a moveu. Redesenhar aqui causaria um pulo visivel a cada solta.
     */
    public function moverPasso(int $id, int $x, int $y): void
    {
        // max(0) tambem aqui: o navegador ja limita, mas coordenada negativa
        // gravada deixa o bloco inalcancavel para sempre.
        $this->passoDo($id)?->update(['x' => max(0, $x), 'y' => max(0, $y)]);
        $this->skipRender();
    }

    public function renomearPasso(int $id, string $nome): void
    {
        $this->estruturaMudou();

        $nome = trim($nome);

        if ($nome === '') {
            return;
        }

        $this->passoDo($id)?->update(['nome' => mb_substr($nome, 0, 60)]);
    }

    public function removerPasso(int $id): void
    {
        $this->estruturaMudou();

        $passo = $this->passoDo($id);

        if (! $passo || $passo->ehInicio()) {
            // O inicio nao se remove: sem ele o fluxo nao tem por onde comecar.
            return;
        }

        $passo->delete();

        if ($this->passoAberto === $id) {
            $this->fecharGaveta();
        }
    }

    public function abrirPasso(int $id): void
    {
        $this->passoAberto = $id;
        $this->acaoAberta = null;
        $this->paletaAberta = false;
    }

    public function fecharGaveta(): void
    {
        $this->passoAberto = null;
        $this->acaoAberta = null;
        $this->paletaAberta = false;
        $this->form = [];
    }

    public function abrirPaleta(int $id): void
    {
        $this->passoAberto = $id;
        $this->acaoAberta = null;
        $this->paletaAberta = true;
    }

    public function adicionarAcao(string $tipo): void
    {
        $this->estruturaMudou();

        $passo = $this->passoDo($this->passoAberto);

        if (! $passo || ! array_key_exists($tipo, ChatbotAction::TIPOS)) {
            return;
        }

        $acao = $this->fluxo()->adicionarAcao($passo, $tipo, $this->configPadrao($tipo));

        $this->paletaAberta = false;
        $this->abrirAcao($acao->id);
    }

    private function configPadrao(string $tipo): array
    {
        return match ($tipo) {
            ChatbotAction::MENSAGEM    => ['texto' => ''],
            ChatbotAction::MENU        => ['texto' => '', 'opcoes' => [['gatilho' => '1', 'rotulo' => '']], 'campo_contato' => ''],
            ChatbotAction::PERGUNTA    => ['texto' => '', 'guardar_em' => '', 'campo_contato' => ''],
            ChatbotAction::ESPERAR     => ['segundos' => 5],
            ChatbotAction::CONDICIONAL => ['campo' => '', 'operador' => 'contem', 'valor' => ''],
            ChatbotAction::TRANSFERIR  => ['team_id' => null, 'aviso' => 'Vou te encaminhar para um atendente.'],
            ChatbotAction::CONCLUIR    => ['aviso' => 'Atendimento encerrado. Obrigado!'],
            ChatbotAction::ETIQUETA    => ['adicionar' => [], 'remover' => []],
            default                    => [],
        };
    }

    public function abrirAcao(int $id): void
    {
        $acao = $this->acaoDo($id);

        if (! $acao) {
            return;
        }

        $this->acaoAberta = $id;
        $this->passoAberto = $acao->step_id;
        $this->paletaAberta = false;

        // O formulario espelha a config; salvar grava de volta. Sem espelho, editar
        // uma opcao de menu exigiria mexer em jsonb direto no wire:model.
        $this->form = array_merge($this->configPadrao($acao->tipo), $acao->config ?? []);
    }

    public function salvarAcao(): void
    {
        $this->estruturaMudou();

        $acao = $this->acaoDo($this->acaoAberta);

        if (! $acao) {
            return;
        }

        $config = $this->form;

        if ($acao->tipo === ChatbotAction::MENU) {
            // Opcao sem gatilho nao e escolhivel: some em vez de virar lixo no menu.
            $config['opcoes'] = collect($config['opcoes'] ?? [])
                ->filter(fn ($o) => trim((string) ($o['gatilho'] ?? '')) !== '')
                ->map(fn ($o) => [
                    'gatilho' => trim((string) $o['gatilho']),
                    'rotulo'  => trim((string) ($o['rotulo'] ?? '')),
                ])
                ->values()
                ->all();
        }

        if ($acao->tipo === ChatbotAction::ESPERAR) {
            $config['segundos'] = max(1, (int) ($config['segundos'] ?? 1));
        }

        $acao->update(['config' => $config]);

        // Gatilho removido deixa aresta orfa apontando para um caminho que nao
        // existe mais; a validacao acusaria e o usuario nao saberia por que.
        $this->limparArestasOrfas($acao->step);

        Notification::make()->success()->title('Ação salva')->send();
    }

    private function limparArestasOrfas(ChatbotStep $passo): void
    {
        $passo->refresh()->load('actions');

        $validos = collect($passo->actions)
            ->flatMap(fn (ChatbotAction $a) => $a->handles())
            ->push(ChatbotEdge::SAIDA)
            ->unique()
            ->all();

        $passo->saidas()->whereNotIn('from_handle', $validos)->delete();
    }

    public function removerAcao(int $id): void
    {
        $this->estruturaMudou();

        $acao = $this->acaoDo($id);

        if (! $acao) {
            return;
        }

        $passo = $acao->step;
        $acao->delete();

        if ($passo) {
            $this->limparArestasOrfas($passo);
        }

        if ($this->acaoAberta === $id) {
            $this->acaoAberta = null;
            $this->form = [];
        }
    }

    public function reordenarAcoes(int $stepId, array $ids): void
    {
        $this->estruturaMudou();

        $passo = $this->passoDo($stepId);

        if ($passo) {
            $this->fluxo()->reordenarAcoes($passo, $ids);
        }
    }

    public function adicionarOpcao(): void
    {
        $opcoes = $this->form['opcoes'] ?? [];
        $proximo = (string) (count($opcoes) + 1);

        $this->form['opcoes'] = array_merge($opcoes, [['gatilho' => $proximo, 'rotulo' => '']]);
    }

    public function removerOpcao(int $i): void
    {
        $opcoes = $this->form['opcoes'] ?? [];
        unset($opcoes[$i]);
        $this->form['opcoes'] = array_values($opcoes);
    }

    public function ligar(int $deId, string $handle, int $paraId): void
    {
        $this->estruturaMudou();

        $de = $this->passoDo($deId);
        $para = $this->passoDo($paraId);

        if ($de && $para) {
            $this->fluxo()->ligar($de, $para, $handle);
        }
    }

    public function desligar(int $deId, string $handle): void
    {
        $this->estruturaMudou();

        $this->passoDo($deId)?->saidas()->where('from_handle', $handle)->delete();
    }

    public function publicar(): void
    {
        $this->estruturaMudou();

        $problemas = $this->fluxo()->publicar($this->record);

        if ($problemas !== []) {
            Notification::make()
                ->danger()
                ->title('O fluxo ainda não pode ser publicado')
                ->body(implode("\n", array_slice($problemas, 0, 4)))
                ->send();

            return;
        }

        $this->record->refresh();

        Notification::make()
            ->success()
            ->title('Fluxo publicado')
            ->body('Versão '.$this->record->versao.'. A partir de agora é este que atende.')
            ->send();
    }

    public function criarExemplo(): void
    {
        $this->estruturaMudou();

        if ($this->record->steps()->where('tipo', ChatbotStep::GRUPO)->exists()) {
            Notification::make()->warning()->title('Este fluxo já tem grupos')->send();

            return;
        }

        $this->fluxo()->criarExemplo($this->record);

        Notification::make()->success()->title('Fluxo de exemplo criado')->send();
    }

    // --------------------------------------------------------------------- apoio

    /**
     * Computed property guarda o resultado dentro do mesmo request. Depois de
     * mexer na estrutura e obrigatorio descartar, senao a tela redesenha com o
     * desenho antigo e o usuario ve a acao "nao ter funcionado".
     */
    private function estruturaMudou(): void
    {
        unset($this->passos, $this->arestas, $this->desenho, $this->problemas);
    }

    private function passoDo(?int $id): ?ChatbotStep
    {
        return $id ? $this->record->steps()->whereKey($id)->first() : null;
    }

    private function acaoDo(?int $id): ?ChatbotAction
    {
        return $id ? $this->record->actions()->whereKey($id)->first() : null;
    }
}
