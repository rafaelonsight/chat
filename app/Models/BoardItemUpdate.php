<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BoardItemUpdate extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];
    protected $casts = ['mencoes' => 'array'];
    public function user() { return $this->belongsTo(User::class); }
}
