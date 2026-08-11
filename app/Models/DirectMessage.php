<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recado direto entre duas pessoas da equipe. */
#[Fillable(['tenant_id', 'de_user_id', 'para_user_id', 'corpo', 'lida_em'])]
class DirectMessage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['lida_em' => 'datetime'];
    }

    public function de(): BelongsTo
    {
        return $this->belongsTo(User::class, 'de_user_id');
    }

    public function para(): BelongsTo
    {
        return $this->belongsTo(User::class, 'para_user_id');
    }

    /**
     * A conversa entre duas pessoas, nos dois sentidos.
     *
     * Escopo e nao consulta solta porque a condicao e facil de escrever pela metade: filtrar so
     * "de mim para ele" devolve um monologo que parece conversa, e o erro nao aparece ate
     * alguem responder.
     */
    public function scopeEntre(Builder $q, int $um, int $outro): Builder
    {
        return $q->where(function (Builder $q) use ($um, $outro) {
            $q->where(fn (Builder $x) => $x->where('de_user_id', $um)->where('para_user_id', $outro))
                ->orWhere(fn (Builder $x) => $x->where('de_user_id', $outro)->where('para_user_id', $um));
        });
    }
}
