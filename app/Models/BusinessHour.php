<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BusinessHour extends Model
{
    use BelongsToTenant;

    public const DIAS = [
        0 => 'Domingo',
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
    ];

    protected $fillable = ['tenant_id', 'channel_id', 'dia_semana', 'ativo', 'intervalos'];

    protected $casts = [
        'ativo'      => 'boolean',
        'intervalos' => 'array',
        'dia_semana' => 'integer',
    ];

    protected $attributes = ['ativo' => true];

    public function nomeDoDia(): string
    {
        return self::DIAS[$this->dia_semana] ?? '?';
    }
}
