<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Channel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'tipo', 'nome', 'instance_name',
        'webhook_secret', 'telefone_e164', 'status', 'conectado_em', 'ultimo_erro',
    ];

    protected $casts = ['conectado_em' => 'datetime'];

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

    public function conectado(): bool
    {
        return $this->status === 'open';
    }
}
