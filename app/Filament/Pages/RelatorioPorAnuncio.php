<?php

namespace App\Filament\Pages;

use App\Models\Conversation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Quantas conversas cada anuncio trouxe.
 *
 * Tela propria, e nao um cartao na visao geral, porque a pergunta e de outra pessoa: quem
 * olha a visao geral quer saber como o atendimento esta indo; quem olha esta tela quer decidir
 * onde colocar dinheiro de anuncio.
 *
 * Le do NOSSO banco, do bloco referral que o webhook guarda. Nao chama a API da Meta:
 * relatorio que depende de chamada externa fica lento no melhor caso e vazio no pior.
 */
class RelatorioPorAnuncio extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?string $navigationLabel = 'Por anúncio';

    protected static ?string $title = 'Conversas por anúncio';

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'relatorios/por-anuncio';

    protected string $view = 'filament.pages.relatorio-por-anuncio';

    public int $dias = 30;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function periodo(int $dias): void
    {
        $this->dias = in_array($dias, [7, 30, 90], true) ? $dias : 30;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportar')
                ->label('Exportar CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->exportar()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $desde = now()->subDays($this->dias);

        $linhas = $this->porAnuncio($desde);

        $total = Conversation::where('created_at', '>=', $desde)->count();
        $deAnuncio = $linhas->sum('conversas');

        return [
            'linhas' => $linhas,
            'total'  => $total,
            // Quantas NAO vieram de anuncio. Sem esse numero, a tela exageraria o peso dos
            // anuncios: a maioria das conversas de um negocio comum chega por outros caminhos,
            // e um relatorio que esconde isso leva a decisao errada de orcamento.
            'outras' => max(0, $total - $deAnuncio),
        ];
    }

    /**
     * Uma linha por anuncio, no periodo.
     *
     * Duas consultas de proposito: a contagem em SQL (agrupar em PHP obrigaria trazer todas
     * as conversas para a memoria) e o titulo depois, pelos ids que sobraram — titulo vive
     * num campo json, e agregar json no banco e mais caro do que buscar um por anuncio.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function porAnuncio(\Illuminate\Support\Carbon $desde): \Illuminate\Support\Collection
    {
        $contagens = Conversation::query()
            ->whereNotNull('origem_id')
            ->where('created_at', '>=', $desde)
            ->selectRaw('origem_id, count(*) as conversas')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as encerradas', [Conversation::ARQUIVADA])
            ->groupBy('origem_id')
            ->orderByDesc('conversas')
            ->get();

        if ($contagens->isEmpty()) {
            return collect();
        }

        $descricoes = Conversation::query()
            ->whereIn('origem_id', $contagens->pluck('origem_id'))
            ->orderBy('id')
            ->get(['origem_id', 'origem_tipo', 'origem'])
            ->keyBy('origem_id');

        return $contagens->map(function ($linha) use ($descricoes) {
            $descricao = $descricoes->get($linha->origem_id);

            return [
                'origem_id'  => $linha->origem_id,
                'tipo'       => $descricao?->origem_tipo,
                'titulo'     => data_get($descricao?->origem, 'titulo')
                    ?: data_get($descricao?->origem, 'texto')
                    ?: '(sem título)',
                'url'        => data_get($descricao?->origem, 'url'),
                'conversas'  => (int) $linha->conversas,
                'encerradas' => (int) $linha->encerradas,
            ];
        });
    }

    public function exportar(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $linhas = $this->porAnuncio(now()->subDays($this->dias));
        $nome = sprintf('conversas-por-anuncio-%dd-%s.csv', $this->dias, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($linhas) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF");

            fputcsv($saida, ['ID do anúncio', 'Tipo', 'Título', 'Conversas', 'Encerradas', 'Link'], ';');

            foreach ($linhas as $l) {
                fputcsv($saida, [
                    $l['origem_id'], $l['tipo'], $l['titulo'],
                    $l['conversas'], $l['encerradas'], $l['url'],
                ], ';');
            }

            fclose($saida);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
