<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaInstagramSignup;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Cache;
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
 * uma vez como constante desta aba.
 *
 * $urlInstagram carrega um 'state' de uso unico guardado no CACHE (nao sessao) sob a
 * propria chave do state — Cache::pull no callback (MetaInstagramSignupController)
 * consome e apaga na mesma operacao. Sessao foi tentada primeiro e tinha o mesmo
 * problema ja corrigido no WhatsApp: e compartilhada entre abas do mesmo navegador, e
 * o mount() roda a cada carregamento — abrir esta tela numa segunda aba (por exemplo,
 * reconectar o canal A numa aba e comecar a conectar um canal novo noutra) sobrescrevia
 * o state/canal da primeira. Cada state agora e sua propria chave de cache: duas abas
 * nao pisam uma na outra porque nao compartilham nenhuma chave.
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

        // 10 minutos: tempo de sobra para completar o login no instagram.com, curto o
        // bastante para nao deixar um state valido pendurado por muito tempo.
        $state = Str::random(32);
        Cache::put("instagram_oauth:{$state}", [
            'tenant_id' => auth()->user()->tenant_id,
            'canal_id'  => $this->canalId,
        ], now()->addMinutes(10));

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
