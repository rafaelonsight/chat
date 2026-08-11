<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * O ponto de partida por linha de produto: chat, sistema, consultoria.
 *
 * Sem modelo, cada proposta comeca de uma pagina em branco — e proposta escrita as pressas sai
 * pior que a anterior, nao melhor.
 */
#[Fillable(['tenant_id', 'nome', 'titulo_padrao', 'blocos', 'itens', 'validade_dias', 'ativo'])]
class ProposalTemplate extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['blocos' => 'array', 'itens' => 'array', 'ativo' => 'boolean'];
    }

    public function scopeAtivos($q)
    {
        return $q->where('ativo', true);
    }
}
