<?php

namespace App\Services;

use App\Models\License;
use App\Models\TrialSecurityEvent;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Protege somente contas de trial explicitamente marcadas para monitoramento.
 *
 * A regra roda no servidor. Ela nao tenta detectar DevTools, extensoes ou o navegador:
 * isso e facilmente contornavel e produziria bloqueios falsos. O bloqueio ocorre apenas
 * quando ha volume de requisicoes claramente mecanizado ou muitas tentativas negadas.
 */
class MonitoramentoDeTrial
{
    private const LIMITE_REQUISICOES_POR_MINUTO = 500;
    private const LIMITE_NEGADAS_EM_DEZ_MINUTOS = 20;

    public function observar(Request $request, int $statusHttp): void
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->tenant?->trial_monitorado || ! $user->tenant->license?->estaValida()) {
            return;
        }

        $tenant = $user->tenant;
        $minuto = RateLimiter::increment("trial-monitor:{$tenant->id}:requisicoes", 60);

        if ($minuto > self::LIMITE_REQUISICOES_POR_MINUTO) {
            $this->suspender($user, $request, $statusHttp, 'volume_automatizado', [
                'requisicoes_no_minuto' => $minuto,
                'limite' => self::LIMITE_REQUISICOES_POR_MINUTO,
            ]);

            return;
        }

        if (! in_array($statusHttp, [401, 403, 404], true)) {
            return;
        }

        $negadas = RateLimiter::increment("trial-monitor:{$tenant->id}:negadas", 600);

        if ($negadas > self::LIMITE_NEGADAS_EM_DEZ_MINUTOS) {
            $this->suspender($user, $request, $statusHttp, 'enumeracao_de_recursos', [
                'respostas_negadas' => $negadas,
                'limite' => self::LIMITE_NEGADAS_EM_DEZ_MINUTOS,
            ]);
        }
    }

    private function suspender(User $user, Request $request, int $statusHttp, string $tipo, array $detalhes): void
    {
        $tenant = $user->tenant;
        $chave = "trial-monitor:{$tenant->id}:suspenso";

        // Evita alertas e gravacoes repetidas enquanto requisicoes pendentes terminam.
        if (! Cache::add($chave, true, now()->addDay())) {
            return;
        }

        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        DB::transaction(function () use ($tenant, $user, $request, $statusHttp, $tipo, $detalhes, $ipHash): void {
            $license = $tenant->license()->lockForUpdate()->first();

            if (! $license || $license->status === License::SUSPENSA) {
                return;
            }

            $license->update([
                'status' => License::SUSPENSA,
                'motivo' => 'Acesso temporariamente indisponível. Entre em contato com o suporte.',
            ]);

            TrialSecurityEvent::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'tipo' => $tipo,
                'gravidade' => 'critica',
                'ip_hash' => $ipHash,
                'rota' => '/'.ltrim($request->path(), '/'),
                'metodo' => $request->method(),
                'status_http' => $statusHttp,
                'detalhes' => $detalhes,
                'ocorreu_em' => now(),
            ]);

            // Requer novo login depois de uma revisao manual do trial.
            DB::table('sessions')->whereIn('user_id', $tenant->users()->pluck('id'))->delete();
        });

        $destinatarios = User::withoutGlobalScope('tenant')
            ->where(function ($query): void {
                $query->where('email', 'owner@example.test')->orWhere('operador', true);
            })
            ->get();

        foreach ($destinatarios as $destinatario) {
            Notification::make()
                ->title('Trial suspenso por segurança')
                ->body("A conta {$tenant->nome} foi suspensa automaticamente após um padrão técnico anormal. Revise a licença antes de reativar.")
                ->icon('heroicon-o-shield-exclamation')
                ->iconColor('danger')
                ->persistent()
                ->actions([
                    Action::make('revisar')
                        ->label('Revisar clientes')
                        ->url('/revenda/clientes')
                        ->markAsRead(),
                ])
                ->sendToDatabase($destinatario);
        }
    }
}
