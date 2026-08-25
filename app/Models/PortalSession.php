<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PortalSession extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'client_portal_id', 'client_admin_id', 'token_hash', 'ip', 'user_agent', 'expires_at', 'revoked_at'];
    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
}
