<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Atendimento;
use App\Filament\Pages\Auth\Perfil;
use App\Http\Middleware\LicencaValida;
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
            // Aviso de conta sem canal, no topo do conteudo de TODA tela. Caixa de entrada
            // vazia porque ninguem escreveu e caixa vazia porque o WhatsApp nunca foi
            // conectado sao a mesma tela; sem isto, a unica forma de o cliente saber a
            // diferenca e telefonar.
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => view('filament.aviso-sem-canal')->render(),
            )
            /*
             * O SINO DO PAINEL.
             *
             * Entrou junto com a mencao em nota: chamar alguem sem ter onde o chamado aparecer
             * nao chama ninguem. E o polling fica DESLIGADO de proposito — o canal
             * App.Models.User.{id} ja estava autorizado e o Reverb ja esta de pe, entao o aviso
             * chega empurrado. Com polling ligado, cada atendente com a tela aberta bateria no
             * servidor a cada poucos segundos para quase sempre ouvir "nada de novo".
             */
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
                    ? Blade::render('<livewire:chat-interno />')
                    : '',
            )
            ->databaseNotifications()
            ->databaseNotificationsPolling(null)
            ->login()
            // Sem isto, funcionario do cliente que esquece a senha depende de alguem com
            // acesso ao banco. Para revender, isso e constrangedor antes de ser tecnico.
            //
            // Depende de e-mail configurado para valer de verdade: com MAIL_MAILER vazio o
            // Laravel escreve a mensagem no log e a tela diz "enviamos" — falha silenciosa
            // que o Diagnostico agora denuncia.
            ->passwordReset()
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
                /*
                 * O AMBAR SAI DO ARQUIVO DO LOGOTIPO, e nao da paleta pronta.
                 *
                 * Color::Amber e #F59E0B; a marca, medida pixel a pixel, e #E8A924 — com o
                 * gradiente do simbolo indo de #E09028 a #F8C830. A diferenca entre os dois
                 * passa despercebida em quase toda a tela, menos num lugar: o botao ao lado do
                 * logotipo. E e justamente ali que ela aparece.
                 */
                'primary' => Color::hex('#E8A924'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Le do APP_NAME em vez de fixar aqui: o nome do produto ainda nao esta
            // decidido, e no dia da decisao ele tem de mudar num lugar so. Um nome escrito
            // em dois lugares vira dois nomes diferentes na primeira troca.
            /*
             * A MARCA VIRA IMAGEM, e o nome continua existindo como texto alternativo — quem
             * usa leitor de tela ouve "Virtus Chat", nao "imagem".
             *
             * DUAS VERSOES, e nao uma: o wordmark e grafite (#404040) e sumiria no fundo escuro
             * do painel. A versao clara troca SO o wordmark por quase-branco; o ambar e
             * idendico nas duas, senao seriam duas marcas em vez de uma marca em dois fundos.
             *
             * O arquivo tem 128px de altura para desenhar 32: em tela retina, imagem no tamanho
             * exato sai serrilhada.
             *
             * Em Closure porque o endereco depende do gerador de URL, que nasce depois do
             * provedor: resolver na hora de desenhar e sempre seguro, resolver aqui depende da
             * ordem de boot.
             */
            ->brandName(config('app.name'))
            ->brandLogo(fn () => asset('marca/virtus-chat.png'))
            ->darkModeBrandLogo(fn () => asset('marca/virtus-chat-claro.png'))
            ->brandLogoHeight('2rem')
            // .ico com 16, 32 e 48: o navegador escolhe o tamanho certo em vez de reduzir um
            // PNG grande e entregar o simbolo borrado na aba.
            ->favicon(fn () => asset('favicon.ico'))
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
                SessaoBloqueada::class,
                // Depois da SessaoBloqueada: sessao destravada e a licenca invalida sao
                // duas cortinas diferentes, e quem esta so com a sessao bloqueada nao
                // precisa saber nada sobre a licenca da conta.
                LicencaValida::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
