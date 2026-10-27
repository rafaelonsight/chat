<?php
namespace App\Push;
use App\Events\MessageStored;
use App\Models\{BrowserPushSubscription, Conversation};
use App\Services\BrowserPush;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
class SendBrowserPush implements ShouldQueueAfterCommit {
    public $tries = 2;
    public $timeout = 120;
    public function handle(MessageStored $event): void {
        $message = $event->message;
        if ($message->direcao !== 'in' || $message->apagada_em || !$message->tenant_id) return;
        TenantContext::runAs((int) $message->tenant_id, function () use ($message) {
            $conversation = Conversation::withoutGlobalScopes()->where('tenant_id', $message->tenant_id)->find($message->conversation_id);
            if (!$conversation) return;
            foreach (BrowserPushSubscription::where('tenant_id', $message->tenant_id)->where('expires_at', '>', now())->with('user')->cursor() as $subscription) {
                $user = $subscription->user;
                if (!$user || $user->operador || (int) $user->tenant_id !== (int) $message->tenant_id || !$user->podeVer($conversation)) continue;
                $license = $user->tenant?->license;
                if ($license && !$license->estaValida()) continue;
                try {
                    app(BrowserPush::class)->send($subscription, [
                        'title' => 'Nova mensagem — Virtus Chat',
                        'body' => 'Você recebeu uma mensagem de cliente. Clique para abrir a conversa.',
                        'tag' => 'virtus-conversa-'.$conversation->id,
                        'url' => '/admin?conversa='.$conversation->id,
                        'userId' => (string) $user->id,
                    ]);
                } catch (\Throwable $e) {
                    logger()->warning('Falha no Web Push', ['subscription_id' => $subscription->id, 'type' => get_class($e)]);
                }
            }
        });
    }
}
