<?php
namespace App\Http\Controllers;
use App\Models\BrowserPushSubscription;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class BrowserPushController extends Controller {
    public function key() {
        abort_unless(config('webpush.public_key'), 503);
        return response()->json(['publicKey' => config('webpush.public_key')])->header('Cache-Control', 'private, no-store');
    }
    public function store(Request $request) {
        $data = $request->validate([
            'endpoint' => 'required|url:https|max:2048',
            'keys.p256dh' => 'required|string|max:100|regex:/^[A-Za-z0-9_-]+={0,2}$/',
            'keys.auth' => 'required|string|max:32|regex:/^[A-Za-z0-9_-]+={0,2}$/',
        ]);
        $parts = parse_url($data['endpoint']);
        $host = strtolower($parts['host'] ?? '');
        $allowed = $host === 'fcm.googleapis.com'
            || $host === 'updates.push.services.mozilla.com'
            || str_ends_with($host, '.push.services.mozilla.com')
            || $host === 'web.push.apple.com'
            || $host === 'notify.windows.com' || str_ends_with($host, '.notify.windows.com');
        $decode = fn ($s) => base64_decode(strtr($s, '-_', '+/'), true);
        $key = $decode($data['keys']['p256dh']);
        $auth = $decode($data['keys']['auth']);
        if (!$allowed || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || strlen($key ?: '') !== 65 || ord($key[0] ?? "\0") !== 4 || strlen($auth ?: '') !== 16) {
            throw ValidationException::withMessages(['endpoint' => 'Inscrição de notificações inválida.']);
        }
        abort_unless($request->user()->tenant_id && !$request->user()->operador, 403);
        BrowserPushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'origin' => $request->getSchemeAndHttpHost(), 'session_hash' => hash('sha256', $request->session()->getId()),
            'expires_at' => now()->addDays(30),
        ]);
        return response()->noContent();
    }
    public function test(Request $request) {
        $data = $request->validate(['endpoint' => 'required|string|max:2048']);
        $subscription = BrowserPushSubscription::where('user_id', $request->user()->id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))->where('expires_at', '>', now())->firstOrFail();
        try {
            $ok = app(\App\Services\BrowserPush::class)->send($subscription, [
                'title' => 'Notificações ativadas — Virtus Chat',
                'body' => 'Este navegador receberá avisos de novas mensagens, mesmo com a página fechada.',
                'tag' => 'virtus-push-teste', 'url' => '/admin', 'userId' => (string) $request->user()->id,
            ]);
        } catch (\Throwable $e) { $ok = false; }
        return response()->json(['ok' => $ok], $ok ? 200 : 502);
    }
    public function destroy(Request $request) {
        $data = $request->validate(['endpoint' => 'required|string|max:2048']);
        BrowserPushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();
        return response()->noContent();
    }
}
