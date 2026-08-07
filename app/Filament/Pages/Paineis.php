<?php

namespace App\Filament\Pages;

use App\Models\Conversation;
use App\Models\Funnel;
use App\Models\FunnelStage;
use BackedEnum;
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

    public string $nomeDoNovoFunil = '';

    public bool $editandoColunas = false;

    /** @var array<int, array{id: ?int, nome: string, cor: string, encerra: bool}> */
    public array $colunas = [];

    public function mount(): void
    {
        $this->funilId = Funnel::orderBy('ordem')->value('id');
        $this->carregarColunas();
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
        $this->carregarColunas();
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
            'foraDoFunil' => $foraDoFunil,
        ];
    }
}
