<?php
namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ProtocoloAtendimento
{
    public function gerar(int $tenantId, ?CarbonInterface $data = null): string
    {
        $conta = Tenant::findOrFail($tenantId);
        $ano = (int) ($data ?? now())->copy()->timezone($conta->fuso_horario ?: config('app.timezone'))->format('Y');
        // Incremento atômico no banco: dois workers não reservam o mesmo número.
        // A sequência pertence à conta e ao ano e nunca depende de contar conversas.
        $r = DB::selectOne('INSERT INTO sequencias_protocolo (tenant_id, ano, ultimo)
            VALUES (?, ?, 1)
            ON CONFLICT (tenant_id, ano) DO UPDATE SET ultimo = sequencias_protocolo.ultimo + 1
            RETURNING ultimo', [$tenantId, $ano]);
        return 'VT-'.substr((string) $ano, -2).str_pad((string) $r->ultimo, 2, '0', STR_PAD_LEFT);
    }
}
