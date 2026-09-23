<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

class ContasParaSuporte extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;
    protected static string|UnitEnum|null $navigationGroup = 'Configurações';
    protected static ?string $navigationParentItem = 'Conta';
    protected static ?string $navigationLabel = 'Contas para suporte';
    protected static ?string $title = 'Contas para suporte';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'suporte/contas';
    protected string $view = 'filament.pages.contas-para-suporte';

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->operador && auth()->user()?->super_admin);
    }

    /** @return Collection<int, Tenant> */
    public function contas(): Collection
    {
        return Tenant::query()->orderBy('nome')->get();
    }

    public function acessar(int $tenantId): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Tenant::findOrFail($tenantId);
        session()->put(TenantContext::CHAVE_SESSAO_SUPORTE, $tenant->id);

        $this->redirect('/admin');
    }
}
