<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um quadro. Cada um com as proprias etapas.
 *
 * Uma empresa que vende e tambem faz suporte precisa de dois processos: "Orcamento,
 * Negociacao, Fechado" nao descreve um chamado tecnico. Forcar os dois no mesmo quadro faz a
 * pessoa inventar etapas que nao servem para nenhum dos dois.
 */
class Funnel extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'nome', 'ordem'];

    protected $casts = ['ordem' => 'integer'];

    protected $attributes = ['ordem' => 0];

    public function stages(): HasMany
    {
        return $this->hasMany(FunnelStage::class)->orderBy('ordem');
    }

    /**
     * Cria um funil ja com etapas.
     *
     * Quadro vazio nao ensina nada: a pessoa abre, ve o branco e fecha. Cinco colunas comuns
     * dao um ponto de partida que ela renomeia em trinta segundos.
     */
    public static function criarCom(string $nome, ?int $tenantId = null): self
    {
        $funil = static::create([
            'tenant_id' => $tenantId ?? auth()->user()->tenant_id,
            'nome'      => $nome,
            'ordem'     => (int) static::max('ordem') + 1,
        ]);

        foreach (FunnelStage::padrao() as $i => $etapa) {
            FunnelStage::create($etapa + [
                'tenant_id' => $funil->tenant_id,
                'funnel_id' => $funil->id,
                'ordem'     => $i,
            ]);
        }

        return $funil;
    }
}
