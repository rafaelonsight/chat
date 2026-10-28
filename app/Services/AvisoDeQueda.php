<?php

namespace App\Services;

use App\Models\BrowserPushSubscription;
use App\Models\Channel;
use App\Models\User;
use App\Notifications\CanalDesconectado;
use Illuminate\Support\Facades\Notification;

/*
 * Avisa o cliente que o WhatsApp dele caiu. Antes so a Onsight sabia (log e diagnostico), e o
 * cliente descobria horas depois, pelo cliente dele reclamando que ninguem respondeu.
 */
class AvisoDeQueda
{
    // A Evolution oscila (close e open de novo em minutos). Um aviso por queda, no maximo um a
    // cada seis horas: alerta que toca toda hora vira alerta que ninguem le.
    public const INTERVALO_HORAS = 6;

    public function canalCaiu(Channel $canal): void
    {
        if ($canal->queda_avisada_em && $canal->queda_avisada_em->gt(now()->subHours(self::INTERVALO_HORAS))) {
            return;
        }

        $admins = User::withoutGlobalScopes()
            ->where('tenant_id', $canal->tenant_id)
            ->where(fn ($q) => $q->where('admin', true)->orWhere('perfil', 'admin'))
            ->get();

        // Grava antes de enviar: se o envio falhar no meio, o proximo close nao manda em dobro.
        $canal->forceFill(['queda_avisada_em' => now()])->saveQuietly();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new CanalDesconectado($canal));
        $this->push($canal, $admins->modelKeys());
    }

    public function canalVoltou(Channel $canal): void
    {
        if ($canal->queda_avisada_em) {
            $canal->forceFill(['queda_avisada_em' => null])->saveQuietly();
        }
    }

    /** @param list<int> $usuarios */
    private function push(Channel $canal, array $usuarios): void
    {
        $assinaturas = BrowserPushSubscription::withoutGlobalScopes()
            ->whereIn('user_id', $usuarios)
            ->where('expires_at', '>', now())
            ->get();

        foreach ($assinaturas as $assinatura) {
            try {
                app(BrowserPush::class)->send($assinatura, [
                    'title' => 'WhatsApp desconectado',
                    'body'  => "O número {$canal->nome} caiu. Toque para reconectar.",
                    'tag'   => 'virtus-canal-'.$canal->id,
                    'url'   => CanalDesconectado::link($canal),
                    'userId' => (string) $assinatura->user_id,
                ]);
            } catch (\Throwable $e) {
                logger()->warning('Falha no Web Push de queda de canal', ['subscription_id' => $assinatura->id, 'type' => get_class($e)]);
            }
        }
    }
}
