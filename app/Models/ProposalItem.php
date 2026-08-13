<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha da proposta: descricao, quantidade, valor, e se repete todo mes. */
// A LIGACAO COM O CATALOGO ENTRA AQUI, e nao entrar foi um bug de verdade: campo fora do
// fillable e descartado em silencio, e o offering_id que o formulario mandava nunca chegava ao
// banco. Nao havia erro nenhum — so a coluna ficando nula, e o relatorio por item vendido
// nascendo vazio meses depois.
#[Fillable(['proposal_id', 'offering_id', 'descricao', 'quantidade', 'valor_unitario', 'recorrente', 'periodicidade', 'ordem'])]
class ProposalItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantidade' => 'decimal:2',
            'valor_unitario' => 'decimal:2',
            'recorrente' => 'boolean',
        ];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * De onde esta linha veio, quando veio do catalogo.
     *
     * NULA QUANDO FOI ESCRITA A MAO, e isso continua permitido: proposta tem item que nao esta
     * no catalogo. A ligacao existe para o relatorio por item vendido — o preco NAO vem daqui,
     * ele fica congelado na linha, senao mexer no catalogo mudaria proposta ja enviada.
     */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(Offering::class);
    }

    public function total(): float
    {
        return (float) $this->quantidade * (float) $this->valor_unitario;
    }
}
