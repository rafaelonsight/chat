<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ClientOnboardingRequest extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';
    public const SUBMITTED = 'submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public const STATUSES = [
        self::PENDING => 'Aguardando preenchimento', self::SUBMITTED => 'Aguardando aprovação',
        self::APPROVED => 'Aprovado', self::REJECTED => 'Recusado',
    ];

    protected $fillable = ['tenant_id', 'created_by', 'reviewed_by', 'created_client_account_id', 'token_hash', 'token_encrypted', 'status', 'company_type', 'payload', 'review_notes', 'submitted_at', 'reviewed_at'];
    protected $casts = ['payload' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    protected $attributes = ['status' => self::PENDING, 'company_type' => ClientAccount::DIRECT, 'payload' => '{}'];

    public static function issue(int $tenantId, ?int $userId): self
    {
        $token = Str::random(64);
        return static::create(['tenant_id' => $tenantId, 'created_by' => $userId, 'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token)]);
    }

    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScope('tenant')->where('token_hash', hash('sha256', $token))->first();
    }

    public function token(): string { return Crypt::decryptString($this->token_encrypted); }
    public function url(): string { return route('onboarding.show', $this->token()); }
    public function createdClient(): BelongsTo { return $this->belongsTo(ClientAccount::class, 'created_client_account_id'); }
}
