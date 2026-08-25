<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardField extends Model
{
    use BelongsToTenant;

    public const TIPOS = [
        'texto' => 'Texto',
        'numero' => 'Número',
        'dinheiro' => 'Dinheiro',
        'data' => 'Data',
        'lista' => 'Lista de opções',
        'checkbox' => 'Sim ou não',
    ];

    protected $fillable = [
        'tenant_id', 'funnel_id', 'nome', 'tipo', 'opcoes', 'obrigatorio', 'ordem',
    ];

    protected $casts = [
        'opcoes' => 'array',
        'obrigatorio' => 'boolean',
        'ordem' => 'integer',
    ];

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }
}
