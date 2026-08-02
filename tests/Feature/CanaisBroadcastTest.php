<?php

use App\Models\{Channel, Contact, Conversation, Tenant, User};
use App\Support\TenantContext;

function tenantCom(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C'])->refresh();
    $ct = Contact::create(['telefone_e164' => '+5511999998888']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id]);

    return [$t, $u, $cv];
}

// Duas armadilhas neste teste, ambas silenciosas:
//
// 1. O phpunit roda com BROADCAST_CONNECTION=null, e o NullBroadcaster tem
//    auth() vazio: autoriza tudo. Com ele o teste passaria ate se a
//    autorizacao fosse deletada.
// 2. Broadcast::channel() registra os callbacks NO DRIVER ativo no boot.
//    Trocar broadcasting.default depois resolve um broadcaster novo, sem
//    canal nenhum — e ai tudo vira 403, inclusive o acesso legitimo.
//
// Por isso trocamos o driver E re-registramos os canais nele.
beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');
});

afterEach(fn () => TenantContext::forget());

it('autoriza o dono da conversa e nega o de outro tenant', function () {
    [, $uA, $cvA] = tenantCom('aa');
    [, $uB] = tenantCom('bb');

    $this->actingAs($uA)->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-conversation.'.$cvA->id,
    ])->assertOk();

    $this->actingAs($uB)->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-conversation.'.$cvA->id,
    ])->assertForbidden();
});

it('nega o canal de lista de outro tenant', function () {
    [$tA, $uA] = tenantCom('cc');
    [$tB] = tenantCom('dd');

    $this->actingAs($uA)->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-tenant.'.$tB->id.'.conversations',
    ])->assertForbidden();

    $this->actingAs($uA)->postJson('/broadcasting/auth', [
        'socket_id'    => '1234.5678',
        'channel_name' => 'private-tenant.'.$tA->id.'.conversations',
    ])->assertOk();
});
