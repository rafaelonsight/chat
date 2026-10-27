<?php
namespace App\Services;
use App\Models\BrowserPushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
class BrowserPush {
    public function send(BrowserPushSubscription $subscription, array $payload): bool {
        if (!config('webpush.private_key')) return false;
        $push = new WebPush(['VAPID' => [
            'subject' => config('webpush.subject'),
            'publicKey' => config('webpush.public_key'), 'privateKey' => config('webpush.private_key'),
        ]], ['TTL' => 300, 'urgency' => 'normal'], 10, ['allow_redirects' => false]);
        $report = $push->sendOneNotification(Subscription::create([
            'endpoint' => $subscription->endpoint, 'contentEncoding' => 'aes128gcm',
            'keys' => ['p256dh' => $subscription->public_key, 'auth' => $subscription->auth_token],
        ]), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($report->isSubscriptionExpired()) $subscription->delete();
        elseif (!$report->isSuccess()) {
            // Não registrar endpoint ou conteúdo: são credenciais do navegador.
            logger()->warning('Web Push recusado', ['subscription_id' => $subscription->id, 'status' => $report->getResponse()?->getStatusCode()]);
        }
        return $report->isSuccess();
    }
}
