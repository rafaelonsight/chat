<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClientAccount extends Model
{
    use BelongsToTenant;

    public const DIRECT = 'direct';

    public const PARTNER = 'partner';

    public const END_CLIENT = 'end_client';

    public const TYPES = [
        self::DIRECT => 'Cliente direto',
        self::PARTNER => 'Parceiro',
        self::END_CLIENT => 'Cliente final de parceiro',
    ];

    protected $fillable = [
        'tenant_id', 'parent_id', 'contact_id', 'type', 'name', 'document', 'legal_name', 'trade_name',
        'email', 'phone', 'cep', 'street', 'number', 'complement', 'district', 'city', 'state', 'notes', 'archived_at',
    ];

    protected $casts = ['archived_at' => 'datetime'];

    protected $attributes = ['type' => self::DIRECT];

    protected static function booted(): void
    {
        static::saving(function (self $client): void {
            if ($client->type !== self::END_CLIENT) {
                $client->parent_id = null;
            }

            if ($client->parent_id) {
                $parent = self::query()->find($client->parent_id);

                if (! $parent || $parent->type !== self::PARTNER || $parent->tenant_id !== $client->tenant_id) {
                    throw new \DomainException('O cliente-pai precisa ser um parceiro da mesma conta.');
                }
            }
        });

        static::created(fn (self $client) => ClientPortal::ensureFor($client));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function requesters(): HasMany
    {
        return $this->hasMany(ClientRequester::class)->orderBy('name');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(ClientProject::class)->orderBy('name');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function portal(): HasOne
    {
        return $this->hasOne(ClientPortal::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(ClientAdmin::class)->orderByDesc('is_primary')->orderBy('name');
    }
}
