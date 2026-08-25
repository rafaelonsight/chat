<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PortalOtp extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'client_portal_id', 'client_admin_id', 'code_hash', 'attempts', 'expires_at', 'used_at'];
    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];
}
