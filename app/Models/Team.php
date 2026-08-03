<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Equipe e fila de atendimento, nao rotulo de pessoa. E o destino do roteamento:
// o chatbot vai setar conversation.team_id e deixar o atendente nulo, fazendo a
// conversa cair em Novos daquela equipe.
class Team extends Model
{
    use BelongsToTenant;

    public const ATENDENTE = 'atendente';

    public const SUPERVISOR = 'supervisor';

    protected $fillable = ['tenant_id', 'nome', 'descricao', 'cor', 'ativa'];

    protected $casts = ['ativa' => 'boolean'];

    protected $attributes = ['ativa' => true];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('papel')
            ->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopeAtivas(Builder $query): Builder
    {
        return $query->where('ativa', true);
    }

    public function supervisores(): BelongsToMany
    {
        return $this->users()->wherePivot('papel', self::SUPERVISOR);
    }
}
