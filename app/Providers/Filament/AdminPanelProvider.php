<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Atendimento;
use App\Filament\Pages\Auth\Perfil;
use App\Http\Middleware\LicencaValida;
use App\Http\Middleware\ContextoDeSuporte;
use App\Http\Middleware\MonitorarTrial;
use App\Http\Middleware\SessaoBloqueada;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->font('Archivo', url: '/fontes/archivo.css', provider: \Filament\FontProviders\LocalFontProvider::class)
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
                fn (): string => view('filament.tempo-real')->render().view('filament.aplicativo-mobile')->render(),
            )
            // Aviso de conta sem canal, no topo do conteudo de TODA tela. Caixa de entrada
            // vazia porque ninguem escreveu e caixa vazia porque o WhatsApp nunca foi
            // conectado sao a mesma tela; sem isto, a unica forma de o cliente saber a
            // diferenca e telefonar.
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => view('filament.modo-suporte')->render().view('filament.aviso-sem-canal')->render(),
            )
            /* O sino recebe menções e alertas de qualidade. A atualização a cada
             * 60 segundos também cobre os avisos duráveis sem evento de broadcast. */
            /*
             * RELOGIO E CONTA, sempre visiveis na barra superior — sem precisar abrir o
             * menu do usuario para saber de qual conta e a sessao aberta. Fica logo antes do
             * sino/avatar (GLOBAL_SEARCH_AFTER e o gancho dentro de fi-topbar-end, na mesma
             * fileira deles), e nao dentro de uma pagina, porque vale para qualquer tela.
             */
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): string => view('filament.seletor-idioma')->render())
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => view('filament.topbar-relogio')->render(),
            )
            /*
             * A BOLHA DO CHAT DA EQUIPE, em toda tela do painel.
             *
             * BODY_END e nao dentro de uma pagina: falar com um colega acontece no meio de
             * outra coisa. Se morasse numa pagina propria, perguntar algo custaria sair de onde
             * se estava — e quem perde o lugar nao pergunta.
             *
             * A guarda do auth()->check() existe porque este gancho tambem roda na tela de
             * login, e uma bolha de chat ali seria um convite para um chat que nao existe.
             */
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => auth()->check()
                    ? Blade::render('<livewire:chat-interno /><livewire:avisos-resposta />').view('filament.navegacao-mobile')->render()
                    : '',
            )
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->login(config('acesso.senha') ? \App\Filament\Pages\Auth\Login::class : [\App\Http\Controllers\AcessoPorCodigoController::class, 'mostrar'])
            // Sem isto, funcionario do cliente que esquece a senha depende de alguem com
            // acesso ao banco. Para revender, isso e constrangedor antes de ser tecnico.
            //
            // Depende de e-mail configurado para valer de verdade: com MAIL_MAILER vazio o
            // Laravel escreve a mensagem no log e a tela diz "enviamos" — falha silenciosa
            // que o Diagnostico agora denuncia.

            // "Meu perfil": trocar o proprio nome e a propria senha. Nao existia — depois de
            // aceitar o convite a pessoa ficava com aquela senha para sempre, ou usava
            // "esqueci minha senha" para trocar uma senha que ela lembra.
            //
            // isSimple: false para a pagina abrir DENTRO do painel, com o menu do lado. A
            // versao simples e uma tela solta, sem saida a nao ser o botao de voltar — serve
            // para cadastro obrigatorio no primeiro acesso, nao para uma pagina que a pessoa
            // visita quando quer.
            ->profile(Perfil::class, isSimple: false)
            ->colors([
                // Cor ativa compartilhada com a navegação e os componentes da v2.
                'primary' => Color::hex('#6338F6'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Le do APP_NAME em vez de fixar aqui: o nome do produto ainda nao esta
            // decidido, e no dia da decisao ele tem de mudar num lugar so. Um nome escrito
            // em dois lugares vira dois nomes diferentes na primeira troca.
            ->brandName(config('app.name'))
            ->brandLogo(fn () => new \Illuminate\Support\HtmlString(view('filament.marca-v2')->render()))
            ->darkModeBrandLogo(fn () => new \Illuminate\Support\HtmlString(view('filament.marca-v2')->render()))
            ->brandLogoHeight('2rem')
            // .ico com 16, 32 e 48: o navegador escolhe o tamanho certo em vez de reduzir um
            // PNG grande e entregar o simbolo borrado na aba.
            ->favicon(fn () => asset('marca/virtus-v2-favicon.svg'))
            // Recolhida por padrao: so os icones. Clicar no icone de um grupo abre
            // a lista com o nome dos itens — comportamento nativo do Filament,
            // habilitado pela combinacao "colapsavel + grupo com icone".
            ->sidebarCollapsibleOnDesktop()
            ->collapsedSidebarWidth('70px')
            ->pages([
                Atendimento::class,
                // Modulo Agenda (e Financeiro): paginas registradas aqui, fora da descoberta, porque
                // na edicao Agenda elas pertencem ao AgendaPanelProvider. Quem nao tem o modulo nao
                // ve o menu nem abre a rota (PertenceAoModulo).
                \App\Filament\Pages\AgendaInicio::class,
                \App\Filament\Pages\AgendaConfiguracoes::class,
                \App\Filament\Pages\AgendaFluxoWhatsApp::class,
                \App\Filament\Pages\AgendaFluxos::class,
                \App\Filament\Pages\AgendaFollowUp::class,
                \App\Filament\Pages\AgendaFinanceiro::class,
                \App\Filament\Pages\AgendaFinanceReports::class,
                \App\Filament\Pages\AgendaBancos::class,
                \App\Filament\Pages\AgendaCategorias::class,
                \App\Filament\Pages\AgendaPaymentMethods::class,
                \App\Filament\Pages\AgendaProdutos::class,
            ])
            ->resources([
                \App\Filament\Resources\Agenda\Professionals\ProfessionalResource::class,
                \App\Filament\Resources\Agenda\Services\ServiceResource::class,
            ])
            // Icone no grupo NAO e decoracao: e a condicao que faz o Filament
            // transformar o grupo em botao com lista suspensa quando a barra esta
            // recolhida ($hasDropdown = label + icone + colapsavel). Sem icone, o
            // grupo recolhido simplesmente desaparece.
            //
            // Consequencia aceita: com icone no grupo, o Filament remove os icones
            // dos itens na lista expandida — grupo OU itens, nunca os dois.
            ->navigationGroups([
                NavigationGroup::make(fn () => __('ui_2a13d05eafbc'))->icon('tabler-user-shield'),
                NavigationGroup::make(fn () => __('ui_a7a1ebe81fa8'))->icon('tabler-chart-bar'),
                NavigationGroup::make(fn () => __('ui_e7a180f8babc'))->icon('tabler-apps'),
                NavigationGroup::make(fn () => __('Agenda'))->icon('tabler-calendar'),
                NavigationGroup::make(fn () => __('Financeiro'))->icon('tabler-cash'),
                NavigationGroup::make(fn () => __('ui_871f24c4cdaf'))->icon('tabler-settings'),
            ])
            // Item sem URL, so para agrupar: o Filament o mantem porque tem
            // filhos, e as paginas se penduram nele por $navigationParentItem.
            ->navigationItems([
                NavigationItem::make('Conta')->label(fn () => __('ui_4d07517575be'))
                    ->group(fn () => __('ui_871f24c4cdaf'))
                    ->icon('tabler-building')
                    ->sort(3),
            ])
            // O menu do perfil vinha so com tema e Sair. Estes dois sao os que uma
            // operacao de atendimento usa de verdade.
            ->userMenuItems([
                MenuItem::make()
                    ->label('Gestão de contas')
                    ->icon('tabler-building')
                    ->url(fn (): string => url('/gestao/clientes'))
                    ->visible(fn (): bool => (bool) auth()->user()?->operador)
                    ->sort(5),

                MenuItem::make()
                    ->label('Bloquear sessão')
                    ->icon('tabler-lock')
                    // postAction e nao url: bloquear muda estado, e o Filament manda
                    // por POST com o token, em vez de virar link clicavel por engano.
                    ->postAction(fn (): string => route('sessao.bloquear'))
                    ->sort(10),

                MenuItem::make()
                    ->label('Limpar dados deste navegador')
                    ->icon('tabler-trash')
                    ->url(fn (): string => route('sessao.limpar-navegador'))
                    ->sort(20),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
                // Antes de qualquer consulta do painel: o operador global precisa usar o
                // tenant escolhido para que os escopos continuem isolando dados corretamente.
                ContextoDeSuporte::class,
                // Depois do StartSession e do Authenticate: a trava e por sessao, e
                // precisa da sessao carregada e do usuario conhecido.
                SessaoBloqueada::class,
                // Depois da SessaoBloqueada: sessao destravada e a licenca invalida sao
                // duas cortinas diferentes, e quem esta so com a sessao bloqueada nao
                // precisa saber nada sobre a licenca da conta.
                LicencaValida::class,
                // Depois da licenca: so acompanha uma sessao autenticada e com acesso valido.
                // O middleware nao examina navegador; registra apenas comportamento de rede
                // inequivocamente anormal em trials marcados para monitoramento.
                MonitorarTrial::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\ExigirPerfilInicial::class,
            ], isPersistent: true);
    }
}
