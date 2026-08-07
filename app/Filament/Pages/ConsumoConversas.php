<?php

namespace App\Filament\Pages;

use App\Models\ConsumoMensal;
use App\Models\Tenant;
use App\Services\Medidor;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Quanto esta conta usou, mes a mes.
 *
 * DUAS LEITURAS NA MESMA TELA, e a diferenca entre elas e o ponto: o mes CORRENTE e calculado
 * ao vivo, porque ainda esta acontecendo; os meses FECHADOS vem da foto tirada no dia 1, e nao
 * mudam nunca mais. Se o fechado fosse recalculado, apagar um canal encolheria um mes ja
 * faturado — e cobranca que muda depois de emitida e discussao com o cliente, que ele ganha.
 *
 * Quem revende ve todas as contas; o cliente ve so a dele. E ele PRECISA ver: numero de fatura
 * que o pagante nao consegue conferir e numero em que ele nao confia.
 */
class ConsumoConversas extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Conta';

    protected static ?string $navigationLabel = 'Consumo de conversas';

    protected static ?string $title = 'Consumo de conversas';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'consumo-conversas';

    protected string $view = 'filament.pages.consumo-conversas';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function getViewData(): array
    {
        $medidor = app(Medidor::class);
        $usuario = auth()->user();
        $operador = (bool) ($usuario->operador ?? false);

        $contas = $operador
            ? Tenant::orderBy('nome')->get()
            : Tenant::where('id', $usuario->tenant_id)->get();

        $agora = now();

        $linhas = $contas->map(function (Tenant $conta) use ($medidor, $agora) {
            $atual = $medidor->doMes($conta->id, $agora);

            $fechados = ConsumoMensal::where('tenant_id', $conta->id)
                ->whereNotNull('fechado_em')
                ->orderByDesc('mes')
                ->limit(12)
                ->get();

            return [
                'conta'    => $conta,
                'atual'    => $atual,
                'fechados' => $fechados,
            ];
        });

        return [
            'linhas'   => $linhas,
            'operador' => $operador,
            'mesAtual' => $agora,
        ];
    }
}
