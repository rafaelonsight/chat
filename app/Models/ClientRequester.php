<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientRequester extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_account_id', 'contact_id', 'name', 'email', 'phone', 'is_admin', 'active',
    ];

    protected $casts = ['is_admin' => 'boolean', 'active' => 'boolean'];

    protected $attributes = ['is_admin' => false, 'active' => true];

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
