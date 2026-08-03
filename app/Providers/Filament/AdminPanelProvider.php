<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\Atendimento;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
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
    public function boot(): void
    {
        // Hover abre e sair fecha, reusando $store.sidebar — o mesmo estado do
        // botao de recolher. Escrever CSS proprio para isso significaria
        // engenharia reversa das regras do Filament e quebraria na proxima versao.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => <<<'HTML'
                <script>
                document.addEventListener('alpine:initialized', () => {
                    const sidebar = document.getElementById('fi-main-sidebar');
                    if (! sidebar) return;

                    // So no desktop: em telas pequenas a sidebar e uma gaveta e
                    // hover nem existe em toque.
                    const desktop = window.matchMedia('(min-width: 1024px)');
                    let sair = null;

                    const abrir = () => {
                        if (! desktop.matches) return;
                        clearTimeout(sair);
                        Alpine.store('sidebar').open();
                    };

                    const fechar = () => {
                        if (! desktop.matches) return;
                        // Pequena folga: sem ela, atravessar a borda com o mouse
                        // faz a barra piscar.
                        sair = setTimeout(() => Alpine.store('sidebar').close(), 220);
                    };

                    sidebar.addEventListener('mouseenter', abrir);
                    sidebar.addEventListener('mouseleave', fechar);

                    // Teclado tambem abre, senao quem navega por Tab fica sem ver
                    // onde esta.
                    sidebar.addEventListener('focusin', abrir);
                    sidebar.addEventListener('focusout', fechar);
                });
                </script>
                HTML,
        );
    }

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
            // Recolhida por padrao: so os icones. Abre ao passar o mouse, pelo
            // mesmo estado que o botao usa (ver o script no fim deste arquivo),
            // para a animacao e o layout serem os nativos do Filament.
            ->sidebarCollapsibleOnDesktop()
            ->pages([
                Atendimento::class,
            ])
            ->navigationGroups([
                'CRM',
                'Relatórios',
                'Aplicações',
                'Configurações',
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
