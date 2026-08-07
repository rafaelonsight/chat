<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O consumo de um mes que ja fechou.
 *
 * Nao usa BelongsToTenant: quem revende precisa ver o de TODOS os clientes para faturar, e um
 * escopo global aqui esconderia justamente o que ele veio buscar. O filtro por conta e
 * explicito em cada consulta — visivel, e nao automatico.
 */
class ConsumoMensal extends Model
{
    protected $table = 'consumo_mensal';

    protected $fillable = [
        'tenant_id', 'mes', 'conversas', 'mensagens_recebidas',
        'mensagens_enviadas', 'contatos_alcancados', 'fechado_em',
    ];

    protected $casts = [
        'mes'                 => 'date',
        'fechado_em'          => 'datetime',
        'conversas'           => 'integer',
        'mensagens_recebidas' => 'integer',
        'mensagens_enviadas'  => 'integer',
        'contatos_alcancados' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function mesLegivel(): string
    {
        static $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        return $meses[$this->mes->month].' de '.$this->mes->year;
    }
}
