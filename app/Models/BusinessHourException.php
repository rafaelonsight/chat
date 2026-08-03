<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BusinessHourException extends Model
{
    use BelongsToTenant;

    protected $table = 'business_hour_exceptions';

    protected $fillable = ['tenant_id', 'data', 'fechado', 'intervalos', 'descricao'];

    protected $casts = [
        'data'       => 'date',
        'fechado'    => 'boolean',
        'intervalos' => 'array',
    ];

    protected $attributes = ['fechado' => true];
}
