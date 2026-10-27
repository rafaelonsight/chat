<?php
use App\Models\{BrowserPushSubscription, Channel, Contact, Conversation, Message, Tenant, User};
use App\Events\MessageStored;
use App\Push\SendBrowserPush;
use App\Services\BrowserPush;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;

function pushContext(): array {
    $tenant = Tenant::create(['nome' => 'Push', 'slug' => 'push-test']);
    TenantContext::set($tenant->id);
    $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'email' => 'push@test.local', 'password' => 'segredo123']);
    return [$tenant, $user];
}
function pushPayload(string $suffix = 'a'): array {
    return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'keys' => [
        'p256dh' => rtrim(strtr(base64_encode("\x04".str_repeat('a', 64)), '+/', '-_'), '='),
        'auth' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '='),
    ]];
}
function pushSubscription(User $user, string $suffix = 'a'): BrowserPushSubscription {
    $data = pushPayload($suffix);
    return BrowserPushSubscription::create([
        'user_id' => $user->id, 'tenant_id' => $user->tenant_id,
        'endpoint' => $data['endpoint'], 'endpoint_hash' => hash('sha256', $data['endpoint']),
        'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
        'origin' => 'https://app.virtus.chat', 'session_hash' => str_repeat('a', 64), 'expires_at' => now()->addDays(30),
    ]);
}
afterEach(fn () => TenantContext::forget());

it('exige login para inscrever um navegador', function () {
    $this->postJson('/browser-push/subscriptions', pushPayload())->assertUnauthorized();
});
it('usa o usuario e tenant autenticados e renova sem duplicar', function () {
    [$tenant, $user] = pushContext();
    $this->actingAs($user)->postJson('/browser-push/subscriptions', pushPayload() + ['user_id' => 999, 'tenant_id' => 999])->assertNoContent();
    $this->postJson('/browser-push/subscriptions', pushPayload())->assertNoContent();
    expect(BrowserPushSubscription::count())->toBe(1);
    $sub = BrowserPushSubscription::first();
    expect($sub->user_id)->toBe($user->id)->and($sub->tenant_id)->toBe($tenant->id);
});
it('recusa endpoints internos, porta alternativa e chaves invalidas', function () {
    [, $user] = pushContext();
    foreach (['https://127.0.0.1/admin', 'https://fcm.googleapis.com.evil.test/push', 'https://fcm.googleapis.com:8443/push', 'https://user@fcm.googleapis.com/push'] as $endpoint) {
        $data = pushPayload(); $data['endpoint'] = $endpoint;
        $this->actingAs($user)->postJson('/browser-push/subscriptions', $data)->assertUnprocessable();
    }
    $data = pushPayload(); $data['keys']['auth'] = 'abc';
    $this->postJson('/browser-push/subscriptions', $data)->assertUnprocessable();
    expect(BrowserPushSubscription::count())->toBe(0);
});
it('nao permite desativar o navegador de outro usuario', function () {
    [$tenant, $user] = pushContext();
    $other = User::create(['tenant_id' => $tenant->id, 'name' => 'Bia', 'email' => 'bia@push.local', 'password' => 'segredo123']);
    pushSubscription($other);
    $this->actingAs($user)->deleteJson('/browser-push/subscriptions', ['endpoint' => pushPayload()['endpoint']])->assertNoContent();
    expect(BrowserPushSubscription::count())->toBe(1);
});
it('envia somente mensagens recebidas a quem tem acesso e inscricao valida', function () {
    [$tenant, $user] = pushContext();
    $channel = Channel::create(['nome' => 'Suporte']);
    $contact = Contact::create(['jid' => '551199999@s.whatsapp.net', 'tipo' => Contact::PESSOA]);
    $conversation = Conversation::create(['channel_id' => $channel->id, 'contact_id' => $contact->id]);
    $user->canais()->attach($channel->id);
    $sub = pushSubscription($user);
    $blocked = User::create(['tenant_id' => $tenant->id, 'name' => 'Bia', 'email' => 'bia@push.local', 'password' => 'segredo123']);
    pushSubscription($blocked, 'blocked');
    $expired = pushSubscription($user, 'expired'); $expired->update(['expires_at' => now()->subDay()]);
    $otherTenant = Tenant::create(['nome' => 'Outro', 'slug' => 'push-other']);
    $other = User::withoutEvents(fn () => User::create(['tenant_id' => $otherTenant->id, 'name' => 'Outro', 'email' => 'other@push.local', 'password' => 'segredo123']));
    $other->forceFill(['super_admin' => true])->saveQuietly();
    pushSubscription($other, 'other-tenant');
    $message = Message::withoutEvents(fn () => Message::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'channel_id' => $channel->id, 'direcao' => 'in', 'tipo' => 'text', 'corpo' => 'Segredo do cliente']));
    $this->mock(BrowserPush::class, function ($mock) use ($sub, $conversation) {
        $mock->shouldReceive('send')->once()->withArgs(fn ($subscription, $payload) => $subscription->id === $sub->id
            && $payload['url'] === '/admin?conversa='.$conversation->id && !str_contains($payload['body'], 'Segredo'));
    });
    $listener = new SendBrowserPush;
    $listener->handle(new MessageStored($message));
    $message->direcao = 'out'; $listener->handle(new MessageStored($message));
});
it('registra um unico listener de envio', function () {
    $listeners = Event::getRawListeners()[MessageStored::class] ?? [];
    expect(count(array_filter($listeners, fn ($listener) => is_string($listener) && str_contains($listener, 'SendBrowserPush'))))->toBe(1);
});
it('remove a inscricao da sessao ao sair e preserva outro dispositivo', function () {
    [, $user] = pushContext();
    $first = pushSubscription($user, 'first');
    $second = pushSubscription($user, 'second');
    $this->actingAs($user)->postJson('/browser-push/subscriptions', pushPayload('first'))->assertNoContent();
    (new \App\Push\RemoveBrowserPushOnLogout)->handle(new \Illuminate\Auth\Events\Logout('web', $user));
    expect($first->fresh())->toBeNull()->and($second->fresh())->not->toBeNull();
});

it('encripta e assina um push com transporte simulado', function () {
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    $clientKeys = \Minishlink\WebPush\VAPID::createVapidKeys();
    $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(201)]);
    $history = [];
    $stack = \GuzzleHttp\HandlerStack::create($mock);
    $stack->push(\GuzzleHttp\Middleware::history($history));
    $push = new \Minishlink\WebPush\WebPush(['VAPID' => ['subject' => 'https://app.virtus.chat'] + $keys], [], 10, ['handler' => $stack, 'allow_redirects' => false]);
    $payload = json_encode(['title' => 'Virtus', 'body' => 'Teste de entrega']);
    $report = $push->sendOneNotification(\Minishlink\WebPush\Subscription::create([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test', 'contentEncoding' => 'aes128gcm',
        'keys' => ['p256dh' => $clientKeys['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')],
    ]), $payload);
    expect($report->isSuccess())->toBeTrue();
    expect($history[0]['request']->getHeaderLine('Authorization'))->toStartWith('vapid ');
    expect($history[0]['request']->getHeaderLine('Content-Encoding'))->toBe('aes128gcm');
    expect((string) $history[0]['request']->getBody())->not->toContain('Teste de entrega');
});

it('envia confirmacao somente para o navegador do usuario', function () {
    [, $user] = pushContext(); $sub = pushSubscription($user);
    $this->mock(BrowserPush::class, function ($mock) use ($sub) {
        $mock->shouldReceive('send')->once()->withArgs(fn ($subscription, $payload) => $subscription->id === $sub->id && $payload['tag'] === 'virtus-push-teste')->andReturn(true);
    });
    $this->actingAs($user)->postJson('/browser-push/test', ['endpoint' => pushPayload()['endpoint']])->assertOk()->assertJson(['ok' => true]);
});
it('nao envia teste ao navegador de outro atendente', function () {
    [$tenant, $user] = pushContext();
    $other = User::create(['tenant_id' => $tenant->id, 'name' => 'Bia', 'email' => 'bia@push.local', 'password' => 'segredo123']);
    pushSubscription($other);
    $this->mock(BrowserPush::class, fn ($mock) => $mock->shouldNotReceive('send'));
    $this->actingAs($user)->postJson('/browser-push/test', ['endpoint' => pushPayload()['endpoint']])->assertNotFound();
});
