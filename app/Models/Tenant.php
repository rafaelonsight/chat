<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'nome', 'slug', 'razao_social', 'documento', 'email', 'telefone', 'fuso_horario',
        'resposta_automatica_ativa', 'resposta_automatica_texto',
    ];

    protected $casts = ['resposta_automatica_ativa' => 'boolean'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
