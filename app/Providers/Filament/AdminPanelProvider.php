<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\Atendimento;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->brandName('OnChat')
            // Recolhida por padrao: so os icones. Clicar no icone de um grupo abre
            // a lista com o nome dos itens — comportamento nativo do Filament,
            // habilitado pela combinacao "colapsavel + grupo com icone".
            ->sidebarCollapsibleOnDesktop()
            ->pages([
                Atendimento::class,
            ])
            // Icone no grupo NAO e decoracao: e a condicao que faz o Filament
            // transformar o grupo em botao com lista suspensa quando a barra esta
            // recolhida ($hasDropdown = label + icone + colapsavel). Sem icone, o
            // grupo recolhido simplesmente desaparece.
            //
            // Consequencia aceita: com icone no grupo, o Filament remove os icones
            // dos itens na lista expandida — grupo OU itens, nunca os dois.
            ->navigationGroups([
                NavigationGroup::make('CRM')->icon(Heroicon::OutlinedUsers),
                NavigationGroup::make('Relatórios')->icon(Heroicon::OutlinedChartBar),
                NavigationGroup::make('Aplicações')->icon(Heroicon::OutlinedSquares2x2),
                NavigationGroup::make('Configurações')->icon(Heroicon::OutlinedCog6Tooth),
            ])
            // Item sem URL, so para agrupar: o Filament o mantem porque tem
            // filhos, e as paginas se penduram nele por $navigationParentItem.
            ->navigationItems([
                NavigationItem::make('Conta')
                    ->group('Configurações')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->sort(3),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
