<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAdmin extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'client_account_id', 'name', 'email', 'is_primary', 'active'];
    protected $casts = ['is_primary' => 'boolean', 'active' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(function (self $admin): void {
            $client = ClientAccount::findOrFail($admin->client_account_id);
            $limit = $client->type === ClientAccount::PARTNER ? 3 : 1;
            $count = static::where('client_account_id', $client->id)->where('active', true)
                ->when($admin->exists, fn ($q) => $q->whereKeyNot($admin->id))->count();
            if ($admin->active && $count >= $limit) throw new \DomainException("Este cliente permite no máximo {$limit} administrador(es) ativo(s).");
            if ($admin->is_primary) static::where('client_account_id', $client->id)->when($admin->exists, fn ($q) => $q->whereKeyNot($admin->id))->update(['is_primary' => false]);
        });
    }

    public function client(): BelongsTo { return $this->belongsTo(ClientAccount::class, 'client_account_id'); }
}
