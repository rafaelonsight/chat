<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A licenca de acesso de um tenant ao produto.
 *
 * NAO USA A TRAIT BelongsToTenant DE PROPOSITO — pelo mesmo motivo que a tela de Clientes
 * (painel de revenda) ja usa withoutGlobalScope('tenant') para listar todos os tenants: quem
 * administra licenca precisa enxergar a de qualquer conta, e o escopo global filtraria pelo
 * tenant de quem esta logado — que, para um operador, nao e nenhum tenant especifico.
 */
class License extends Model
{
    public const TRIAL = 'trial';

    public const ATIVA = 'ativa';

    public const EM_ATRASO = 'em_atraso';

    public const SUSPENSA = 'suspensa';

    public const CANCELADA = 'cancelada';

    public const STATUS = [
        self::TRIAL     => 'Período de teste',
        self::ATIVA     => 'Ativa',
        self::EM_ATRASO => 'Em atraso',
        self::SUSPENSA  => 'Suspensa',
        self::CANCELADA => 'Cancelada',
    ];

    protected $fillable = [
        'tenant_id', 'status', 'plano', 'inicia_em', 'vence_em', 'motivo', 'alterada_por',
    ];

    protected $casts = [
        'inicia_em' => 'datetime',
        'vence_em'  => 'datetime',
    ];

    protected $attributes = [
        'status' => self::TRIAL,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function alteradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alterada_por');
    }

    /**
     * Da para usar o produto?
     *
     * ATIVA sempre vale, sem olhar data — e o admin de revenda quem decide quando termina,
     * ativando outro status. TRIAL vale so ate vencer: sem vence_em quer dizer trial sem
     * prazo, e conta como valido. Qualquer outro status (em_atraso, suspensa, cancelada) e
     * SEMPRE invalido, mesmo que vence_em ainda esteja no futuro — a data deixa de importar
     * no momento em que alguem muda o status a mao.
     */
    public function estaValida(): bool
    {
        if ($this->status === self::ATIVA) {
            return true;
        }

        if ($this->status === self::TRIAL) {
            return ! $this->vence_em || $this->vence_em->isFuture();
        }

        return false;
    }

    public function nomeDoStatus(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }
}
