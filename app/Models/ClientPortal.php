<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ClientPortal extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'client_account_id', 'token_hash', 'token_encrypted', 'active', 'rotated_at', 'last_accessed_at'];

    protected $casts = ['active' => 'boolean', 'rotated_at' => 'datetime', 'last_accessed_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public static function ensureFor(ClientAccount $client): self
    {
        return static::firstOrCreate(['client_account_id' => $client->id], static::tokenData($client));
    }

    public function rotate(): string
    {
        $token = Str::random(64);
        $this->update(['token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token), 'rotated_at' => now()]);

        return $token;
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        return route('portal.show', $this->token());
    }

    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScope('tenant')->where('token_hash', hash('sha256', $token))->where('active', true)->first();
    }

    private static function tokenData(ClientAccount $client): array
    {
        $token = Str::random(64);

        return ['tenant_id' => $client->tenant_id, 'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token), 'active' => true];
    }
}
