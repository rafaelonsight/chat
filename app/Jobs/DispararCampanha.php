<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Disparador;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Um "tique" da campanha: manda o lote de um minuto e se reagenda.
 *
 * POR QUE ASSIM, E NAO UM JOB POR DESTINATARIO AGENDADO DE UMA VEZ.
 *
 * Enfileirar cinco mil jobs com atraso crescente funciona ate alguem querer PAUSAR: os cinco
 * mil ja estao na fila, e cancelar um por um nao existe. Ficaria a escolha entre nao ter pausa
 * ou ter uma pausa que mente. Com o tique, pausar e mudar uma coluna — o proximo tique le,
 * ve, e para.
 *
 * O mesmo vale para mudar o ritmo no meio: o proximo tique ja usa o numero novo.
 */
class DispararCampanha implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $campaignId) {}

    /** Dois tiques da mesma campanha ao mesmo tempo mandariam o lote em dobro. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('campanha:'.$this->campaignId))->releaseAfter(30)];
    }

    public function handle(Disparador $disparador): void
    {
        $campanha = Campaign::withoutGlobalScope('tenant')->find($this->campaignId);

        if (! $campanha || ! $campanha->rodando()) {
            return; // pausada, cancelada ou apagada entre um tique e outro
        }

        TenantContext::runAs($campanha->tenant_id, function () use ($campanha, $disparador) {
            $agora = now();

            // Fora da janela: nao manda nada e volta quando abrir. Disparo as 23h nao e so
            // falta de educacao — e assedio de consumo no CDC e denuncia no WhatsApp.
            $permitido = $disparador->dentroDaJanela($campanha, $agora);

            if ($permitido->gt($agora)) {
                self::dispatch($campanha->id)->delay($permitido);

                return;
            }

            if ($campanha->status === Campaign::AGENDADA) {
                $campanha->forceFill([
                    'status'      => Campaign::ENVIANDO,
                    'iniciada_em' => $campanha->iniciada_em ?? now(),
                ])->save();
            }

            $lote = $campanha->recipients()
                ->where('status', CampaignRecipient::PENDENTE)
                ->with('contact')
                ->orderBy('id')
                ->limit($campanha->por_minuto)
                ->get();

            if ($lote->isEmpty()) {
                $campanha->forceFill([
                    'status'       => Campaign::CONCLUIDA,
                    'concluida_em' => now(),
                ])->save();

                return;
            }

            foreach ($lote as $destinatario) {
                $this->enviarPara($campanha, $destinatario, $disparador);
            }

            // Proximo lote em um minuto. Se acabou, o proximo tique fecha a campanha — deixar
            // o fechamento para o tique seguinte evita concluir cedo demais quando o lote veio
            // exatamente do tamanho do que faltava.
            self::dispatch($campanha->id)->delay(now()->addMinute());
        });
    }

    private function enviarPara(Campaign $campanha, CampaignRecipient $destinatario, Disparador $disparador): void
    {
        $contato = $destinatario->contact;

        // RECONFERE na hora de mandar, e nao so quando a fila foi montada. Entre uma coisa e
        // outra podem ter passado horas, e alguem pode ter pedido para sair no meio da
        // campanha — justamente por causa dela.
        $motivo = match (true) {
            ! $contato                 => 'contato removido',
            (bool) $contato->opt_out_em => 'pediu para sair da lista',
            (bool) $contato->bloqueado_em => 'contato bloqueado',
            (bool) $contato->arquivado_em => 'contato arquivado',
            default                    => null,
        };

        if ($motivo) {
            $destinatario->forceFill(['status' => CampaignRecipient::PULADA, 'motivo' => $motivo])->save();

            return;
        }

        try {
            $conversa = Conversation::abertaOuNova(
                $campanha->channel_id,
                $contato->id,
                $campanha->tenant_id,
            );

            $ehTemplate = $campanha->exigeTemplate();

            $mensagem = Message::create([
                'tenant_id'       => $campanha->tenant_id,
                'conversation_id' => $conversa->id,
                'channel_id'      => $campanha->channel_id,
                'direcao'         => 'out',
                'tipo'            => $ehTemplate ? 'template' : 'text',
                'corpo'           => $ehTemplate
                    ? $campanha->template?->renderizar((array) $campanha->template_valores)
                    : $campanha->corpo,
                // automatica: campanha nao e o atendente falando, e nao pode contar como
                // resposta dele no relatorio de tempo.
                'automatica'      => true,
                'status'          => Message::STATUS_QUEUED,
            ]);

            $ehTemplate
                ? SendTemplateMessage::dispatch($mensagem->id, $campanha->meta_template_id, (array) $campanha->template_valores)
                : SendTextMessage::dispatch($mensagem->id);

            // ultima_msg_em SIM, ultima_entrada_em NAO: quem falou fomos nos. A janela de 24h
            // pertence a quem procurou, e campanha nao abre janela nenhuma.
            $conversa->update(['ultima_msg_em' => now()]);

            $destinatario->forceFill([
                'status'     => CampaignRecipient::ENVIADA,
                'message_id' => $mensagem->id,
                'enviada_em' => now(),
                'motivo'     => null,
            ])->save();
        } catch (\Throwable $e) {
            // Falha de UM destinatario nao para a campanha: o lote continua e esta linha fica
            // com o motivo. Parar tudo por um numero invalido faria mil pessoas nao receberem
            // por causa de uma.
            $destinatario->forceFill([
                'status' => CampaignRecipient::FALHOU,
                'motivo' => mb_substr($e->getMessage(), 0, 190),
            ])->save();
        }
    }
}
