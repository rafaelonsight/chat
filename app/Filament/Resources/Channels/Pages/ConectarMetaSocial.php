<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaInstagramSignup;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;

/**
 * Messenger e Instagram, DOIS logins independentes — nao e o mesmo fluxo com uma
 * bifurcacao. O Messenger usa o FB.login() classico (Pagina do Facebook); o
 * Instagram loga direto no instagram.com, sem Pagina nenhuma no meio (ver
 * MetaInstagramSignup - foi descoberto testando de verdade que este app tem o
 * Instagram no produto novo da Meta, que nao aceita a permissao antiga vinculada a
 * Pagina).
 *
 * Mesmo cuidado do WhatsApp com reconexao: $canalId vem da URL (?canal=), carregado
 * uma vez como constante desta aba — nao de sessao, que e compartilhada entre abas e
 * misturaria duas reconexoes concorrentes (ver MetaEmbeddedSignupController).
 *
 * $urlInstagram carrega o 'state' de uso unico ja guardado na sessao (ver
 * MetaInstagramSignup::urlDeAutorizacao) — gerado no mount(), antes de qualquer
 * clique, porque o callback do Instagram precisa confirmar contra ele.
 */
class ConectarMetaSocial extends Page
{
    protected static string $resource = ChannelResource::class;

    protected string $view = 'filament.resources.channels.pages.conectar-meta-social';

    public bool $reconectando = false;

    public ?int $canalId = null;

    /** 'messenger' ou 'instagram' — so importa quando $reconectando, para saber qual secao mostrar. */
    public ?string $tipoCanal = null;

    public string $urlInstagram = '';

    public ?string $erroInstagram = null;

    public function mount(): void
    {
        $canalId = request()->query('canal');

        if ($canalId !== null) {
            // Global scope do Channel (BelongsToTenant + Acesso) ja restringe ao
            // tenant logado — um id de outro tenant simplesmente nao aparece aqui.
            $canal = Channel::query()->whereIn('tipo', [Channel::MESSENGER, Channel::INSTAGRAM])->find($canalId);

            if ($canal !== null) {
                $this->canalId = $canal->id;
                $this->tipoCanal = $canal->tipo;
                $this->reconectando = true;
            }
        }

        $state = Str::random(32);
        session(['instagram_oauth_state' => $state, 'instagram_oauth_canal' => $this->canalId]);

        $this->urlInstagram = app(MetaInstagramSignup::class)->urlDeAutorizacao(
            route('canais.instagram.callback'),
            $state,
        );

        $this->erroInstagram = session('erro_instagram');
    }

    public function getTitle(): string
    {
        if (! $this->reconectando) {
            return 'Conectar Facebook e Instagram';
        }

        return $this->tipoCanal === Channel::INSTAGRAM ? 'Reconectar Instagram' : 'Reconectar Messenger';
    }
}
