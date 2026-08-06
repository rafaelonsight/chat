<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Channel extends Model
{
    use BelongsToTenant;

    /** Baileys pela Evolution: numero comum, sem regra de janela e COM grupo. */
    public const EVOLUTION = 'evolution';

    /** API oficial da Meta: janela de 24h, template aprovado, e SEM grupo. */
    public const META_CLOUD = 'meta_cloud';

    public const TIPOS = [
        self::EVOLUTION  => 'WhatsApp via Evolution (não oficial)',
        self::META_CLOUD => 'WhatsApp oficial (Meta Cloud API)',
    ];

    protected $fillable = [
        'tenant_id', 'tipo', 'nome', 'instance_name',
        'webhook_secret', 'telefone_e164', 'status', 'conectado_em', 'ultimo_erro',
        'meta_phone_number_id', 'meta_waba_id', 'meta_token', 'meta_business_id',
    ];

    protected $casts = [
        'conectado_em' => 'datetime',
        // encrypted: o token da Meta nao fica legivel no banco. Quem tira um dump para
        // depurar, ou quem consegue ler uma tabela, nao sai com credencial de cliente na
        // mao. A chave e a APP_KEY, que nao mora no banco — sem ela o dump nao serve.
        'meta_token'   => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::creating(function (Channel $c) {
            // A Evolution nao assina o payload do webhook: a autenticidade vem
            // deste segredo embutido na URL.
            $c->webhook_secret ??= Str::random(48);
        });

        static::created(function (Channel $c) {
            if (! $c->instance_name) {
                $c->forceFill(['instance_name' => "t{$c->tenant_id}-c{$c->id}"])->saveQuietly();
            }
        });
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function webhookUrl(): string
    {
        return url("/webhooks/evolution/{$this->id}/{$this->webhook_secret}");
    }

    /**
     * Este canal e limitado pela janela de 24 horas?
     *
     * Pergunta ao TIPO e nao ao sistema: no Baileys a regra nao existe, e avisar o
     * atendente de um limite que nao vale ali seria inventar restricao — ele
     * aprenderia a ignorar o aviso, inclusive quando fosse verdade.
     */
    public function exigeJanela(): bool
    {
        return $this->tipo === self::META_CLOUD;
    }

    /**
     * Grupo de WhatsApp NAO existe na API oficial.
     *
     * E o motivo de o hibrido continuar necessario: quem usa grupo de bairro vai
     * manter os dois canais lado a lado.
     */
    public function permiteGrupo(): bool
    {
        return $this->tipo === self::EVOLUTION;
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? (string) $this->tipo;
    }

    public function conectado(): bool
    {
        return $this->status === 'open';
    }
}
