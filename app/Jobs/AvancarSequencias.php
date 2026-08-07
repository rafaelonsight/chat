<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\SequenceEnrollment;
use App\Services\Cadenciador;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Um tique das sequencias: manda o que venceu e agenda o proximo passo.
 *
 * Roda pelo agendador, nao se reagenda como o da campanha. A diferenca: campanha tem inicio e
 * fim e um ritmo proprio; sequencia e um estado permanente do sistema, e um job que se
 * reagenda para sempre e um job que ninguem consegue parar sem mexer no Redis.
 */
class AvancarSequencias implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Teto por rodada, para uma fila represada nao virar rajada de mil mensagens. */
    public const LOTE = 60;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sequencias'))->dontRelease()];
    }

    public function handle(Cadenciador $cadenciador): void
    {
        $vencidas = SequenceEnrollment::query()
            ->where('status', SequenceEnrollment::ATIVA)
            ->whereNotNull('proximo_em')
            ->where('proximo_em', '<=', now())
            ->with(['sequence.steps', 'contact'])
            ->orderBy('proximo_em')
            ->limit(self::LOTE)
            ->get();

        foreach ($vencidas as $inscricao) {
            $this->avancar($inscricao, $cadenciador);
        }
    }

    private function avancar(SequenceEnrollment $inscricao, Cadenciador $cadenciador): void
    {
        $sequencia = $inscricao->sequence;
        $contato = $inscricao->contact;

        if (! $sequencia || ! $sequencia->ativa) {
            $inscricao->parar('a sequência foi desligada');

            return;
        }

        // RECONFERE na hora de mandar. Entre um passo e outro passam dias, e nesses dias a
        // pessoa pode ter pedido para sair, sido bloqueada ou arquivada — justamente por causa
        // da sequencia.
        if (! $cadenciador->podeReceber($contato)) {
            $inscricao->parar('o contato saiu da lista ou foi bloqueado');

            return;
        }

        $passo = $sequencia->steps->firstWhere('ordem', $inscricao->proximo_passo);

        if (! $passo) {
            $inscricao->forceFill([
                'status'       => SequenceEnrollment::CONCLUIDA,
                'encerrada_em' => now(),
                'proximo_em'   => null,
            ])->save();

            return;
        }

        TenantContext::runAs($sequencia->tenant_id, function () use ($inscricao, $sequencia, $contato, $passo, $cadenciador) {
            $conversa = Conversation::abertaOuNova($sequencia->channel_id, $contato->id, $sequencia->tenant_id);

            // Fora da janela de 24h o canal oficial recusa texto livre. Pular com o motivo
            // escrito e melhor que enfileirar uma mensagem que vai falhar e encher a conversa
            // de bolha vermelha.
            if (! $conversa->podeEnviarLivre()) {
                $inscricao->parar('a janela de 24 horas fechou antes deste passo');

                return;
            }

            $mensagem = Message::create([
                'tenant_id'       => $sequencia->tenant_id,
                'conversation_id' => $conversa->id,
                'channel_id'      => $sequencia->channel_id,
                'direcao'         => 'out',
                'tipo'            => 'text',
                'corpo'           => $passo->corpo,
                'automatica'      => true,
                'status'          => Message::STATUS_QUEUED,
            ]);

            SendTextMessage::dispatch($mensagem->id);

            $conversa->update(['ultima_msg_em' => now()]);

            $proximo = $sequencia->steps->firstWhere('ordem', '>', $passo->ordem);

            if (! $proximo) {
                $inscricao->forceFill([
                    'status'       => SequenceEnrollment::CONCLUIDA,
                    'encerrada_em' => now(),
                    'proximo_em'   => null,
                ])->save();

                return;
            }

            $inscricao->forceFill([
                'proximo_passo' => $proximo->ordem,
                'proximo_em'    => $cadenciador->quando($sequencia, $proximo->atraso_horas),
            ])->save();
        });
    }
}
