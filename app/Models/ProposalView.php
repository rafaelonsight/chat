<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Uma abertura da proposta. Cada uma vira linha — a contagem e a informacao. */
#[Fillable(['proposal_id', 'vista_em', 'ip', 'agente'])]
class ProposalView extends Model
{
    protected function casts(): array
    {
        return ['vista_em' => 'datetime'];
    }
}
