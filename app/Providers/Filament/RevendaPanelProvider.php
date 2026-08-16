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
            ->domain('admin.virtus.chat')
            ->path('/')
            ->login()
            ->colors([
                // Mesma cor de marca do painel do produto — ver AdminPanelProvider.
                'primary' => Color::hex('#E8A924'),
            ])
            ->brandName(config('app.name').' — Revenda')
            ->discoverResources(in: app_path('Filament/Revenda/Resources'), for: 'App\Filament\Revenda\Resources')
            ->discoverPages(in: app_path('Filament/Revenda/Pages'), for: 'App\Filament\Revenda\Pages')
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
