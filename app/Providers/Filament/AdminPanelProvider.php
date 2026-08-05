<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\Atendimento;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
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
            // O viteTheme acima traz SO o CSS. Sem esta linha o resources/js/app.js
            // era compilado e nunca servido: window.Echo nao existia, o navegador nao
            // abria websocket nenhum, e todo ouvinte de tempo real dos componentes
            // ficava morto — a caixa de entrada so mudava quando algo forcava um
            // request do Livewire (clicar numa aba, por exemplo).
            //
            // HEAD_END e nao o fim do body: o modulo do Vite e "defer", entao roda
            // depois do HTML e ANTES do DOMContentLoaded, que e quando o Livewire
            // inicia e procura o window.Echo. Se rodasse depois, o Livewire ja teria
            // desistido dos ouvintes com "Laravel Echo cannot be found".
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.tempo-real')->render(),
            )
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
            // O menu do perfil vinha so com tema e Sair. Estes dois sao os que uma
            // operacao de atendimento usa de verdade.
            ->userMenuItems([
                MenuItem::make()
                    ->label('Bloquear sessão')
                    ->icon(Heroicon::OutlinedLockClosed)
                    // postAction e nao url: bloquear muda estado, e o Filament manda
                    // por POST com o token, em vez de virar link clicavel por engano.
                    ->postAction(fn (): string => route('sessao.bloquear'))
                    ->sort(10),

                MenuItem::make()
                    ->label('Limpar dados deste navegador')
                    ->icon(Heroicon::OutlinedTrash)
                    ->url(fn (): string => route('sessao.limpar-navegador'))
                    ->sort(20),
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
                // Depois do StartSession e do Authenticate: a trava e por sessao, e
                // precisa da sessao carregada e do usuario conhecido.
                \App\Http\Middleware\SessaoBloqueada::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
