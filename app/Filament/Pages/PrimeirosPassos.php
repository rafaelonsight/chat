<?php

namespace App\Filament\Pages;

use App\Services\PrimeirosPassos as Servico;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class PrimeirosPassos extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationLabel = 'Primeiros passos';

    protected static ?string $title = 'Primeiros passos';

    // Primeiro item do grupo: quem ainda nao configurou nada precisa tropecar nele.
    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'primeiros-passos';

    protected string $view = 'filament.pages.primeiros-passos';

    // So administrador configura conta; atendente nao tem o que fazer aqui.
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    /**
     * O numero vermelho no menu conta SO o que e essencial.
     *
     * Se contasse os recomendados, um consultorio de uma pessoa so — que legitimamente nao quer
     * equipes nem etiquetas — carregaria um alerta para sempre. Alarme que nunca apaga e alarme
     * que se aprende a ignorar, e ai o dia em que o canal cair ninguem olha.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $faltam = app(Servico::class)->faltamEssenciais();

        return $faltam > 0 ? (string) $faltam : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return list<array<string, mixed>> */
    public function essenciais(): array
    {
        return array_values(array_filter(
            app(Servico::class)->passos(),
            fn (array $p) => $p['peso'] === Servico::ESSENCIAL,
        ));
    }

    /** @return list<array<string, mixed>> */
    public function recomendados(): array
    {
        return array_values(array_filter(
            app(Servico::class)->passos(),
            fn (array $p) => $p['peso'] === Servico::RECOMENDADO,
        ));
    }

    public function tudoEssencialPronto(): bool
    {
        return app(Servico::class)->faltamEssenciais() === 0;
    }
}
