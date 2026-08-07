<?php

namespace App\Services;

use App\Models\ConsumoMensal;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Quanto uma conta usou num mes.
 *
 * A UNIDADE E A CONVERSA INICIADA, e essa escolha precisa estar escrita porque muda a fatura.
 *
 * Nao e "mensagem": duas empresas com o mesmo numero de clientes pagariam valores muito
 * diferentes so porque uma escreve mais. Nao e "contato": um cliente que volta todo mes daria
 * receita uma vez so. Conversa iniciada e o que mais se parece com "atendimento prestado", que
 * e o que o produto entrega.
 *
 * As mensagens aparecem ao lado como informacao, nao como base de calculo.
 */
class Medidor
{
    /** @return array{conversas: int, recebidas: int, enviadas: int, contatos: int} */
    public function medir(int $tenantId, Carbon $inicio, Carbon $fim): array
    {
        $conversas = Conversation::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$inicio, $fim]);

        $mensagens = Message::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$inicio, $fim]);

        return [
            'conversas' => (clone $conversas)->count(),
            'recebidas' => (clone $mensagens)->where('direcao', 'in')->count(),
            'enviadas'  => (clone $mensagens)->where('direcao', 'out')->count(),
            'contatos'  => (clone $conversas)->distinct('contact_id')->count('contact_id'),
        ];
    }

    public function doMes(int $tenantId, Carbon $mes): array
    {
        return $this->medir(
            $tenantId,
            $mes->copy()->startOfMonth(),
            $mes->copy()->endOfMonth(),
        );
    }

    /**
     * Grava a foto do mes. Idempotente: rodar duas vezes nao duplica nem some.
     *
     * NAO REESCREVE mes ja fechado. Se reescrevesse, a foto perderia a razao de existir — o
     * numero voltaria a mudar depois de faturado, que e exatamente o que ela evita.
     */
    public function fechar(Tenant $conta, Carbon $mes): ConsumoMensal
    {
        $primeiro = $mes->copy()->startOfMonth()->startOfDay();

        $existente = ConsumoMensal::where('tenant_id', $conta->id)
            ->whereDate('mes', $primeiro)
            ->first();

        if ($existente && $existente->fechado_em) {
            return $existente;
        }

        $n = $this->doMes($conta->id, $mes);

        return ConsumoMensal::updateOrCreate(
            ['tenant_id' => $conta->id, 'mes' => $primeiro],
            [
                'conversas'           => $n['conversas'],
                'mensagens_recebidas' => $n['recebidas'],
                'mensagens_enviadas'  => $n['enviadas'],
                'contatos_alcancados' => $n['contatos'],
                'fechado_em'          => now(),
            ],
        );
    }
}
