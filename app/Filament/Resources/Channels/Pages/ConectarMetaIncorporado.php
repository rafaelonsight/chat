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
 * Quem decide se e criacao ou reconexao e a sessao, guardada aqui e lida por
 * MetaEmbeddedSignupController::retorno(), porque o retorno chega por um REDIRECT da
 * Meta, e nao ha como carimbar o id do canal na URL de retorno sem arriscar nao bater
 * com o redirect_uri cadastrado no Painel de Apps.
 */
class ConectarMetaIncorporado extends Page
{
    protected static string $resource = ChannelResource::class;

    protected string $view = 'filament.resources.channels.pages.conectar-meta-incorporado';

    public bool $reconectando = false;

    public function mount(): void
    {
        $canalId = request()->query('canal');

        if ($canalId === null) {
            // Criacao nova: nenhuma reconexao pendente deve sobrar de uma tentativa
            // anterior cancelada, senao o retorno atualizaria o canal errado.
            session()->forget('meta_reconectar_canal_id');

            return;
        }

        // Global scope do Channel (BelongsToTenant + Acesso) ja restringe ao tenant
        // logado sozinho — um id de outro tenant simplesmente nao aparece aqui.
        $canal = Channel::query()->where('tipo', Channel::META_CLOUD)->find($canalId);

        if ($canal === null) {
            session()->forget('meta_reconectar_canal_id');

            return;
        }

        session(['meta_reconectar_canal_id' => $canal->id]);
        $this->reconectando = true;
    }

    public function getTitle(): string
    {
        return $this->reconectando ? 'Reconectar WhatsApp oficial' : 'Conectar WhatsApp oficial';
    }
}
