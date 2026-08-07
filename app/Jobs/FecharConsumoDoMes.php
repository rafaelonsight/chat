<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Medidor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Tira a foto do mes que acabou, para toda conta.
 *
 * Roda no comeco de cada mes. Fecha o mes ANTERIOR e nao o corrente: fechar um mes que ainda
 * esta acontecendo daria um numero pela metade com cara de definitivo.
 */
class FecharConsumoDoMes implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public ?string $mes = null) {}

    public function handle(Medidor $medidor): void
    {
        $mes = $this->mes
            ? Carbon::parse($this->mes)
            : now()->subMonthNoOverflow();

        Tenant::query()->orderBy('id')->chunkById(100, function ($contas) use ($medidor, $mes) {
            foreach ($contas as $conta) {
                $medidor->fechar($conta, $mes);
            }
        });
    }
}
