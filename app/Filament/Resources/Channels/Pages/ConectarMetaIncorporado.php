<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use Filament\Resources\Pages\Page;

/**
 * O botao "Continuar com o Facebook" do Cadastro Incorporado.
 *
 * Substitui, para quem tem esse caminho disponivel, o formulario manual de Phone Number
 * ID / WABA ID / Token: o popup da Meta devolve os tres, e quem confirma sozinho e o
 * JS desta tela (ver a view). O formulario manual continua existindo em
 * create?tipo=meta_cloud, para quem precisar dele (rotacao de token, caso que o
 * Cadastro Incorporado nao cobre).
 *
 * Tambem serve para RECONECTAR um canal que ja existe (token expirado/revogado): a
 * mesma tela, so que chamada com ?canal={id} — ver EditChannel::getHeaderActions().
 *
 * $canalId fica exposto ao JS da view como uma CONSTANTE desta pagina, carregada uma
 * vez — deliberadamente NAO fica em sessao. Sessao e por navegador, compartilhada
 * entre abas; se o mesmo admin abrisse uma segunda reconexao numa aba diferente antes
 * de terminar esta, a sessao passaria a apontar para o canal errado e o retorno da
 * Meta atualizaria o canal errado, em silencio. Cada aba com o proprio canalId evita
 * isso: ver MetaEmbeddedSignupController.
 */
class ConectarMetaIncorporado extends Page
{
    protected static string $resource = ChannelResource::class;

    protected string $view = 'filament.resources.channels.pages.conectar-meta-incorporado';

    public bool $reconectando = false;

    public ?int $canalId = null;

    public function mount(): void
    {
        $canalId = request()->query('canal');

        if ($canalId === null) {
            return;
        }

        // Global scope do Channel (BelongsToTenant + Acesso) ja restringe ao tenant
        // logado sozinho — um id de outro tenant simplesmente nao aparece aqui.
        $canal = Channel::query()->where('tipo', Channel::META_CLOUD)->find($canalId);

        if ($canal === null) {
            return;
        }

        $this->canalId = $canal->id;
        $this->reconectando = true;
    }

    public function getTitle(): string
    {
        return $this->reconectando ? 'Reconectar WhatsApp oficial' : 'Conectar WhatsApp oficial';
    }
}
