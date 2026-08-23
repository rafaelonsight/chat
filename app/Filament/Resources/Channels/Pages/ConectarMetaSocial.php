<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use Filament\Resources\Pages\Page;

/**
 * O botao "Continuar com o Facebook" de Messenger/Instagram.
 *
 * Diferente do Cadastro Incorporado do WhatsApp (link hospedado pela Meta, com wizard
 * proprio que devolve WABA+numero prontos), aqui o FB.login() classico so devolve
 * consentimento — a Meta nao mostra seletor de Pagina nesse fluxo. Por isso esta
 * mesma tela tem DOIS estados (ver a view): primeiro o botao de login, depois a lista
 * de Paginas para escolher, sem sair da pagina.
 *
 * Mesmo cuidado do WhatsApp com reconexao: $canalId vem da URL (?canal=), carregado
 * uma vez como constante desta aba — nao de sessao, que e compartilhada entre abas e
 * misturaria duas reconexoes concorrentes (ver MetaEmbeddedSignupController).
 */
class ConectarMetaSocial extends Page
{
    protected static string $resource = ChannelResource::class;

    protected string $view = 'filament.resources.channels.pages.conectar-meta-social';

    public bool $reconectando = false;

    public ?int $canalId = null;

    /** 'messenger' ou 'instagram' — so importa quando $reconectando, para o titulo e para travar a escolha de Instagram na lista de Paginas. */
    public ?string $tipoCanal = null;

    public function mount(): void
    {
        $canalId = request()->query('canal');

        if ($canalId === null) {
            return;
        }

        // Global scope do Channel (BelongsToTenant + Acesso) ja restringe ao tenant
        // logado — um id de outro tenant simplesmente nao aparece aqui.
        $canal = Channel::query()->whereIn('tipo', [Channel::MESSENGER, Channel::INSTAGRAM])->find($canalId);

        if ($canal === null) {
            return;
        }

        $this->canalId = $canal->id;
        $this->tipoCanal = $canal->tipo;
        $this->reconectando = true;
    }

    public function getTitle(): string
    {
        if (! $this->reconectando) {
            return 'Conectar Facebook e Instagram';
        }

        return $this->tipoCanal === Channel::INSTAGRAM ? 'Reconectar Instagram' : 'Reconectar Messenger';
    }
}
