<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'titulo', 'atalho', 'corpo', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    protected $attributes = ['ativo' => true];

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    // Marcadores resolvidos na hora de usar, nao na hora de salvar: o mesmo
    // modelo serve para qualquer conversa.
    public function renderizar(?Conversation $conversa, ?User $usuario): string
    {
        return \App\Support\Marcadores::aplicar((string) $this->corpo, $conversa, $usuario);
    }

    public const MARCADORES = \App\Support\Marcadores::DISPONIVEIS;
}
