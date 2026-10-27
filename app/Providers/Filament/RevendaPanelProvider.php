<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * O bastidor do fornecedor: cadastro de tenant e licenca.
 *
 * PAINEL SEPARADO, e nao paginas escondidas dentro do painel do produto (que e como
 * viviam antes). Painel proprio da domino proprio (admin.virtus.chat) e pastas de
 * descoberta proprias (Filament/Revenda/*), para nao colidir com o painel 'admin' nem
 * depender so de canAccess() por pagina para esconder do cliente o que e nosso.
 *
 * QUEM ENTRA: so operador — ver User::canAccessPanel().
 */
class RevendaPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('revenda')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Archivo', url: '/fontes/archivo.css', provider: \Filament\FontProviders\LocalFontProvider::class)
            ->domain('agenda.virtus.chat')
            ->path('gestao')
            ->sidebarCollapsibleOnDesktop()
            ->collapsedSidebarWidth('70px')
            ->login([\App\Http\Controllers\AcessoPorCodigoController::class, 'mostrar'])
            ->colors([
                // Mesma cor de marca do painel do produto — ver AdminPanelProvider.
                'primary' => Color::hex('#6338F6'),
            ])
            ->favicon(fn () => asset('marca/virtus-v2-favicon.svg'))
            ->brandName(config('app.name').' — Gestão')
            ->brandLogo(fn () => new \Illuminate\Support\HtmlString(view('filament.marca-v2')->render()))
            ->userMenuItems([
                \Filament\Navigation\MenuItem::make()
                    ->label('Abrir atendimento')
                    ->icon('tabler-message-2-heart')
                    ->url(fn (): string => url('/admin')),
            ])
            ->discoverResources(in: app_path('Filament/Revenda/Resources'), for: 'App\Filament\Revenda\Resources')
            ->discoverPages(in: app_path('Filament/Revenda/Pages'), for: 'App\Filament\Revenda\Pages')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                \App\Http\Middleware\IdiomaDaInterface::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\ExigirPerfilInicial::class,
            ], isPersistent: true);
    }
}
