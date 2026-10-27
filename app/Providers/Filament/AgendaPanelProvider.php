<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AgendaInicio;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Perfil;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Tags\TagResource;
use App\Http\Middleware\RequireAgendaTenant;
use App\Http\Middleware\IdiomaDaInterface;
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

class AgendaPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')
            ->login(Login::class)->passwordReset(\App\Filament\Pages\Auth\RequestPasswordReset::class,\App\Filament\Pages\Auth\ResetPassword::class)
            ->profile(Perfil::class, isSimple: false)
            ->brandName(config('app.name'))
            ->renderHook(\Filament\View\PanelsRenderHook::HEAD_START, fn () => view('filament.agenda-update-mode'))
            ->brandLogo(fn () => view('filament.marca-agenda'))
            ->favicon(asset('marca/virtus-v2-favicon.svg'))
            ->font('Archivo', url: '/fontes/archivo.css', provider: \Filament\FontProviders\LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors(['primary' => Color::hex('#6338F6')])
            ->sidebarCollapsibleOnDesktop()
            ->pages([\App\Filament\Pages\AgendaCampaigns::class,\App\Filament\Pages\AgendaFollowUp::class,\App\Filament\Pages\AgendaOperations::class,\App\Filament\Pages\AgendaFluxos::class,\App\Filament\Pages\AgendaFluxoWhatsApp::class,\App\Filament\Pages\AgendaCategorias::class,\App\Filament\Pages\AgendaBancos::class,\App\Filament\Pages\AgendaPaymentMethods::class,\App\Filament\Pages\AgendaFinanceReports::class,\App\Filament\Pages\AgendaProdutos::class,\App\Filament\Pages\AgendaFinanceiro::class,AgendaInicio::class,\App\Filament\Pages\AgendaConfiguracoes::class,\App\Filament\Pages\AgendaCanais::class,\App\Filament\Pages\AgendaWhatsAppQr::class,\App\Filament\Pages\AgendaAtendimento::class])
            ->databaseNotifications()->databaseNotificationsPolling('30s')
            ->resources([
                \App\Filament\Resources\Agenda\Services\ServiceResource::class,
                \App\Filament\Resources\Agenda\Professionals\ProfessionalResource::class,
                ContactResource::class, UserResource::class, TeamResource::class, TagResource::class])
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                AuthenticateSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class,
                SubstituteBindings::class, RequireAgendaTenant::class, \App\Http\Middleware\RequireAgendaAccess::class, IdiomaDaInterface::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class], isPersistent: true);
    }
}
