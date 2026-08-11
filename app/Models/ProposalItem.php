<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha da proposta: descricao, quantidade, valor, e se repete todo mes. */
#[Fillable(['proposal_id', 'descricao', 'quantidade', 'valor_unitario', 'recorrente', 'periodicidade', 'ordem'])]
class ProposalItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantidade'     => 'decimal:2',
            'valor_unitario' => 'decimal:2',
            'recorrente'     => 'boolean',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function total(): float
    {
        return (float) $this->quantidade * (float) $this->valor_unitario;
    }
}
