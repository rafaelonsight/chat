<?php

namespace App\Filament\Pages;

use App\Models\BoardField;
use App\Models\BoardItem;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\Tag;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * O funil: colunas que a empresa define, conversas como cartoes.
 *
 * O CARTAO E A CONVERSA e nao o contato, porque a mesma pessoa pode ter dois assuntos ao mesmo
 * tempo — um orcamento fechado em julho e outro em negociacao em agosto. Com o contato como
 * cartao, o segundo apagaria o primeiro.
 */
class Paineis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Painéis';

    protected static ?string $title = 'Funil';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'paineis';

    protected string $view = 'filament.pages.paineis';

    /** Qual quadro esta aberto. */
    public ?int $funilId = null;

    public bool $editandoFunis = false;

    /** Formulario dedicado: criar painel nunca deve parecer renomear o atual. */
    public bool $criandoFunil = false;

    public string $nomeDoNovoFunil = '';

    public bool $editandoColunas = false;

    public bool $editandoCampos = false;

    /** @var array<int, array{id: ?int, nome: string, tipo: string, opcoes: string, obrigatorio: bool}> */
    public array $campos = [];

    public ?int $itemId = null;

    public ?int $itemEtapaId = null;

    public bool $editandoItem = false;

    public string $tituloDoItem = '';

    public string $descricaoDoItem = '';

    public ?int $contatoDoItem = null;

    public ?int $responsavelDoItem = null;

    public string $prioridadeDoItem = 'normal';

    public ?string $prazoDoItem = null;

    /** @var array<int|string, mixed> */
    public array $valoresDoItem = [];

    /** @var array<int, int|string> */
    public array $etiquetasDoItem = [];

    /** @var array<int, array{id: ?int, nome: string, cor: string, encerra: bool}> */
    public array $colunas = [];

    public function mount(): void
    {
        // A aba e um link real (?painel=ID): recarregar, compartilhar ou voltar no navegador
        // abre o mesmo painel. Sem isso a selecao existia apenas na memoria do Livewire.
        $solicitado = (int) request()->query('painel', 0);

        $this->funilId = $solicitado && Funnel::whereKey($solicitado)->exists()
            ? $solicitado
            : Funnel::orderBy('ordem')->value('id');
        $this->carregarColunas();
        $this->carregarCampos();
        if ($item = (int) request()->query('item', 0)) {
            $this->editarItem($item);
        }
    }

    /** Troca de quadro. Cada um tem as proprias etapas e os proprios cartoes. */
    public function abrirFunil(int $id): void
    {
        if (! Funnel::whereKey($id)->exists()) {
            return;
        }

        $this->funilId = $id;
        $this->editandoColunas = false;
        $this->carregarColunas();
        $this->carregarCampos();
    }

    public function criarFunil(): void
    {
        $nome = trim($this->nomeDoNovoFunil);

        if ($nome === '') {
            $this->addError('nomeDoNovoFunil', 'Dê um nome ao funil.');

            return;
        }

        $this->funilId = Funnel::criarCom(mb_substr($nome, 0, 60))->id;
        $this->nomeDoNovoFunil = '';
        $this->criandoFunil = false;
        $this->editandoFunis = false;
        $this->carregarColunas();
        $this->carregarCampos();

        Notification::make()
            ->title('Painel criado')
            ->body('O painel anterior continua disponível nas abas acima.')
            ->success()
            ->send();
    }

    public function abrirCriacao(): void
    {
        $this->resetErrorBag('nomeDoNovoFunil');
        $this->nomeDoNovoFunil = '';
        $this->editandoFunis = false;
        $this->criandoFunil = true;
    }

    public function cancelarCriacao(): void
    {
        $this->resetErrorBag('nomeDoNovoFunil');
        $this->nomeDoNovoFunil = '';
        $this->criandoFunil = false;
    }

    public function renomearFunil(int $id, string $nome): void
    {
        $nome = trim($nome);

        if ($nome === '') {
            return;
        }

        Funnel::whereKey($id)->update(['nome' => mb_substr($nome, 0, 60)]);
    }

    /**
     * Apaga o quadro inteiro.
     *
     * As etapas vao junto por cascata, e os cartoes voltam para fora do funil — a conversa em
     * si NUNCA e apagada. Perder atendimento porque alguem reorganizou o quadro seria perder
     * dado por causa de arrumacao.
     */
    public function excluirFunil(int $id): void
    {
        $funil = Funnel::find($id);

        if (! $funil) {
            return;
        }

        $funil->delete();

        $this->funilId = Funnel::orderBy('ordem')->value('id');
        $this->carregarColunas();
    }

    private function carregarColunas(): void
    {
        if (! $this->funilId) {
            $this->colunas = [];

            return;
        }

        $this->colunas = FunnelStage::where('funnel_id', $this->funilId)->orderBy('ordem')->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'nome' => $e->nome, 'cor' => $e->cor, 'encerra' => $e->encerra,
            ])->values()->all();
    }

    private function carregarCampos(): void
    {
        if (! $this->funilId) {
            $this->campos = [];

            return;
        }

        $this->campos = BoardField::where('funnel_id', $this->funilId)->orderBy('ordem')->get()
            ->map(fn (BoardField $campo) => [
                'id' => $campo->id,
                'nome' => $campo->nome,
                'tipo' => $campo->tipo,
                'opcoes' => implode(', ', $campo->opcoes ?? []),
                'obrigatorio' => $campo->obrigatorio,
            ])->values()->all();
    }

    public function editarCampos(): void
    {
        $this->carregarCampos();
        $this->editandoCampos = true;
        $this->editandoColunas = false;
    }

    public function adicionarCampo(): void
    {
        $this->campos[] = [
            'id' => null, 'nome' => '', 'tipo' => 'texto', 'opcoes' => '', 'obrigatorio' => false,
        ];
    }

    public function removerCampo(int $indice): void
    {
        unset($this->campos[$indice]);
        $this->campos = array_values($this->campos);
    }

    public function salvarCampos(): void
    {
        $this->validate([
            'campos' => 'array',
            'campos.*.nome' => 'required|string|max:60',
            'campos.*.tipo' => 'required|in:'.implode(',', array_keys(BoardField::TIPOS)),
        ]);

        $mantidos = [];

        foreach (array_values($this->campos) as $ordem => $dados) {
            $valores = array_values(array_filter(array_map('trim', explode(',', $dados['opcoes'] ?? ''))));
            $atributos = [
                'nome' => trim($dados['nome']),
                'tipo' => $dados['tipo'],
                'opcoes' => $dados['tipo'] === 'lista' ? $valores : null,
                'obrigatorio' => (bool) $dados['obrigatorio'],
                'ordem' => $ordem,
            ];

            $campo = ! empty($dados['id'])
                ? tap(BoardField::where('funnel_id', $this->funilId)->findOrFail($dados['id']))->update($atributos)
                : BoardField::create($atributos + [
                    'tenant_id' => auth()->user()->tenant_id,
                    'funnel_id' => $this->funilId,
                ]);

            $mantidos[] = $campo->id;
        }

        BoardField::where('funnel_id', $this->funilId)->whereNotIn('id', $mantidos)->delete();
        $this->editandoCampos = false;
        $this->carregarCampos();

        Notification::make()->title('Campos do painel atualizados')->success()->send();
    }

    public function abrirNovoItem(int $etapaId): void
    {
        $etapa = FunnelStage::where('funnel_id', $this->funilId)->find($etapaId);

        if (! $etapa) {
            return;
        }

        $this->resetValidation();
        $this->itemId = null;
        $this->itemEtapaId = $etapa->id;
        $this->tituloDoItem = '';
        $this->descricaoDoItem = '';
        $this->contatoDoItem = null;
        $this->responsavelDoItem = auth()->id();
        $this->prioridadeDoItem = 'normal';
        $this->prazoDoItem = null;
        $this->valoresDoItem = [];
        $this->etiquetasDoItem = [];
        $this->editandoItem = true;
    }

    public function editarItem(int $id): void
    {
        $item = BoardItem::where('funnel_id', $this->funilId)->find($id);

        if (! $item) {
            return;
        }

        $this->resetValidation();
        $this->itemId = $item->id;
        $this->itemEtapaId = $item->funnel_stage_id;
        $this->tituloDoItem = $item->titulo;
        $this->descricaoDoItem = (string) $item->descricao;
        $this->contatoDoItem = $item->contact_id;
        $this->responsavelDoItem = $item->responsavel_id;
        $this->prioridadeDoItem = $item->prioridade;
        $this->prazoDoItem = $item->prazo_em?->format('Y-m-d\TH:i');
        $this->valoresDoItem = $item->dados ?? [];
        $this->etiquetasDoItem = $item->tags()->pluck('tags.id')->all();
        $this->editandoItem = true;
    }

    public function cancelarItem(): void
    {
        $this->editandoItem = false;
        $this->resetValidation();
    }

    public function alternarEtiqueta(int $etiquetaId): void
    {
        if (! Tag::deItem()->whereKey($etiquetaId)->exists()) {
            return;
        }

        $selecionadas = array_map('intval', $this->etiquetasDoItem);

        if (in_array($etiquetaId, $selecionadas, true)) {
            $selecionadas = array_values(array_diff($selecionadas, [$etiquetaId]));
        } else {
            $selecionadas[] = $etiquetaId;
        }

        $this->etiquetasDoItem = $selecionadas;
    }

    public function salvarItem(): void
    {
        $regras = [
            'tituloDoItem' => 'required|string|max:160',
            'descricaoDoItem' => 'nullable|string|max:5000',
            'contatoDoItem' => 'nullable|integer',
            'responsavelDoItem' => 'nullable|integer',
            'prioridadeDoItem' => 'required|in:baixa,normal,alta,urgente',
            'prazoDoItem' => 'nullable|date',
            'etiquetasDoItem' => 'array',
            'etiquetasDoItem.*' => 'integer',
        ];

        foreach (BoardField::where('funnel_id', $this->funilId)->get() as $campo) {
            $regras['valoresDoItem.'.$campo->id] = $campo->obrigatorio ? 'required' : 'nullable';
        }

        $this->validate($regras, [], ['tituloDoItem' => 'título']);

        $etapa = FunnelStage::where('funnel_id', $this->funilId)->find($this->itemEtapaId);

        if (! $etapa) {
            $this->addError('tituloDoItem', 'A coluna escolhida não existe mais.');

            return;
        }

        $contato = $this->contatoDoItem ? Contact::find($this->contatoDoItem) : null;
        $responsavel = $this->responsavelDoItem ? User::where('tenant_id', auth()->user()->tenant_id)->findOrFail($this->responsavelDoItem) : null;

        $atributos = [
            'tenant_id' => auth()->user()->tenant_id,
            'funnel_id' => $this->funilId,
            'funnel_stage_id' => $etapa->id,
            'contact_id' => $contato?->id,
            'responsavel_id' => $responsavel?->id,
            'titulo' => trim($this->tituloDoItem),
            'descricao' => trim($this->descricaoDoItem) ?: null,
            'prioridade' => $this->prioridadeDoItem,
            'prazo_em' => $this->prazoDoItem ?: null,
            'dados' => $this->valoresDoItem,
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use ($atributos, &$mensagem) {
        if ($this->itemId) {
            $item = BoardItem::where('funnel_id', $this->funilId)->findOrFail($this->itemId);
            $item->update($atributos);
            $mensagem = 'Item atualizado';
        } else {
            $item = BoardItem::create($atributos + ['etapa_em' => now()]);
            $mensagem = 'Item criado';
        }

        $etiquetasValidas = Tag::deItem()->whereIn('id', $this->etiquetasDoItem)->pluck('id');
        $item->tags()->sync($etiquetasValidas->mapWithKeys(fn ($id) => [$id => [
            'origem' => 'manual', 'aplicado_por' => auth()->id(), 'created_at' => now(),
        ]])->all());
        });

        $this->editandoItem = false;
        Notification::make()->title($mensagem)->success()->send();
    }

    public function moverItem(int $itemId, int $etapaId): void
    {
        $item = BoardItem::where('funnel_id', $this->funilId)->find($itemId);
        $etapa = FunnelStage::where('funnel_id', $this->funilId)->find($etapaId);

        if ($item && $etapa) {
            \Illuminate\Support\Facades\DB::transaction(fn () => $item->update(['funnel_stage_id' => $etapa->id, 'etapa_em' => now()]));
        }
    }

    #[\Livewire\Attributes\On('status-item-alterado')]
    public function atualizarStatusDoItem(): void
    {
        if ($this->itemId) {
            $this->itemEtapaId = BoardItem::where('funnel_id', $this->funilId)->find($this->itemId)?->funnel_stage_id;
        }
    }

    public function arquivarItem(int $itemId): void
    {
        BoardItem::where('funnel_id', $this->funilId)->whereKey($itemId)
            ->update(['arquivado_em' => now()]);

        $this->editandoItem = false;
        Notification::make()->title('Item arquivado')->success()->send();
    }

    /**
     * Cria as colunas de exemplo.
     *
     * Funil vazio nao ensina nada: a pessoa abre, ve um quadro em branco e fecha. Cinco colunas
     * comuns dao um ponto de partida que ela renomeia em trinta segundos.
     */
    public function criarPadrao(): void
    {
        if (Funnel::exists()) {
            return;
        }

        $this->funilId = Funnel::criarCom('Funil')->id;
        $this->carregarColunas();
    }

    public function editarColunas(): void
    {
        $this->carregarColunas();
        $this->editandoColunas = true;
    }

    public function adicionarColuna(): void
    {
        $this->colunas[] = ['id' => null, 'nome' => '', 'cor' => 'cinza', 'encerra' => false];
    }

    public function removerColuna(int $i): void
    {
        unset($this->colunas[$i]);
        $this->colunas = array_values($this->colunas);
    }

    public function salvarColunas(): void
    {
        $this->validate([
            'colunas'         => 'required|array|min:1',
            'colunas.*.nome'  => 'required|string|max:40',
        ], [], ['colunas' => 'colunas', 'colunas.*.nome' => 'nome da coluna']);

        $mantidos = [];

        foreach (array_values($this->colunas) as $i => $c) {
            $etapa = $c['id']
                ? tap(FunnelStage::findOrFail($c['id']))->update([
                    'nome' => trim($c['nome']), 'cor' => $c['cor'],
                    'ordem' => $i, 'encerra' => (bool) $c['encerra'],
                ])
                : FunnelStage::create([
                    'tenant_id' => auth()->user()->tenant_id,
                    'funnel_id' => $this->funilId,
                    'nome' => trim($c['nome']), 'cor' => $c['cor'],
                    'ordem' => $i, 'encerra' => (bool) $c['encerra'],
                ]);

            $mantidos[] = $etapa->id;
        }

        // Apagar coluna NAO apaga conversa: a chave estrangeira e nullOnDelete, e o cartao
        // volta para fora do funil. Sumir com o atendimento porque alguem reorganizou o quadro
        // seria perder dado por causa de arrumacao.
        // So as deste quadro: sem o recorte, salvar as colunas de um funil apagaria as
        // dos outros — e os cartoes deles cairiam todos para fora de uma vez.
        FunnelStage::where('funnel_id', $this->funilId)->whereNotIn('id', $mantidos)->delete();

        $this->editandoColunas = false;
        $this->carregarColunas();
    }

    /** Move o cartao. Chamado pelo arraste e tambem pelos botoes, que existem para o celular. */
    public function mover(int $conversationId, ?int $etapaId): void
    {
        $conversa = Conversation::find($conversationId);

        if (! $conversa) {
            return;
        }

        $etapa = $etapaId ? FunnelStage::find($etapaId) : null;

        // Etapa de outra conta nao existe nesta consulta; sem etapa, o cartao sai do funil.
        if ($etapaId && ! $etapa) {
            return;
        }

        $conversa->moverPara($etapa);
    }

    public function getViewData(): array
    {
        $funis = Funnel::orderBy('ordem')->orderBy('id')->get();

        $etapas = $this->funilId
            ? FunnelStage::where('funnel_id', $this->funilId)->orderBy('ordem')->get()
            : collect();

        $conversas = Conversation::query()
            ->whereIn('funnel_stage_id', $etapas->pluck('id'))
            ->with(['contact', 'atendente'])
            ->orderByDesc('etapa_em')
            ->get()
            ->groupBy('funnel_stage_id');

        $itens = BoardItem::query()
            ->where('funnel_id', $this->funilId)
            ->whereNull('arquivado_em')
            ->with(['contact', 'responsavel', 'tags'])
            ->orderByDesc('etapa_em')
            ->get()
            ->groupBy('funnel_stage_id');

        // Quem ainda nao esta no funil. Sem esta coluna, por o primeiro cartao no quadro
        // exigiria abrir a conversa e voltar — e ninguem descobre sozinho que da para fazer
        // isso.
        $foraDoFunil = Conversation::query()
            ->whereNull('funnel_stage_id')
            ->where('status', '!=', Conversation::ARQUIVADA)
            ->with(['contact', 'atendente'])
            ->orderByDesc('ultima_msg_em')
            ->limit(20)
            ->get();

        return [
            'funis'       => $funis,
            'funilAberto' => $funis->firstWhere('id', $this->funilId),
            'etapas'      => $etapas,
            'conversas'   => $conversas,
            'itens'       => $itens,
            'camposDoPainel' => BoardField::where('funnel_id', $this->funilId)->orderBy('ordem')->get(),
            'contatos' => Contact::ativos()->where('tipo', Contact::PESSOA)
                ->orderBy('nome')->limit(300)->get(['id', 'nome', 'telefone_e164', 'email']),
            'responsaveis' => User::orderBy('name')->get(['id', 'name']),
            'etiquetasDeItem' => Tag::deItem()->orderBy('nome')->get(),
            'foraDoFunil' => $foraDoFunil,
        ];
    }
}
