<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\NewConversation;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function cenarioBusca(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Eu', 'email' => "eu@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    return [$t, $u, $c];
}

function contato(string $telefone, ?string $nome = null): Contact
{
    return Contact::create([
        'jid'           => Contact::jidDoTelefone($telefone),
        'tipo'          => Contact::PESSOA,
        'telefone_e164' => $telefone,
        'nome'          => $nome,
    ]);
}

function grupo(string $jid, string $nome): Contact
{
    return Contact::create(['jid' => $jid, 'tipo' => Contact::GRUPO, 'nome' => $nome]);
}

afterEach(fn () => TenantContext::forget());

it('sem termo nao lista nada', function () {
    [, $u] = cenarioBusca('bs0');
    TenantContext::set($u->tenant_id);
    contato('+5584911111111', 'Joao');
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->assertViewHas('contatos', fn ($cs) => $cs->isEmpty());
});

it('busca contato salvo por nome', function () {
    [, $u] = cenarioBusca('bs1');
    TenantContext::set($u->tenant_id);
    $joao = contato('+5584911111111', 'Joao da Silva');
    contato('+5584922222222', 'Maria');
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'joao')
        ->assertViewHas('contatos', fn ($cs) => $cs->pluck('id')->all() === [$joao->id])
        ->assertSee('Joao da Silva');
});

it('busca contato salvo por telefone, mesmo digitando so o final', function () {
    [, $u] = cenarioBusca('bs2');
    TenantContext::set($u->tenant_id);
    $joao = contato('+5584996143373', 'Joao');
    contato('+5584922222222', 'Maria');
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', '6143373')
        ->assertViewHas('contatos', fn ($cs) => $cs->pluck('id')->all() === [$joao->id]);
});

it('grupo aparece na busca', function () {
    [, $u] = cenarioBusca('bs3');
    TenantContext::set($u->tenant_id);
    $g = grupo('120363011111111111@g.us', 'Bairro Centro');
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'bairro')
        ->assertViewHas('contatos', fn ($cs) => $cs->pluck('id')->all() === [$g->id]);
});

it('nao lista contato de outro tenant', function () {
    [, $uA] = cenarioBusca('bs4');
    TenantContext::set($uA->tenant_id);
    contato('+5584911111111', 'Alvo');
    TenantContext::forget();

    [, $uB] = cenarioBusca('bs5');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'alvo')
        ->assertViewHas('contatos', fn ($cs) => $cs->isEmpty());
});

// Contato salvo ja tem JID: perguntar de novo ao WhatsApp e round-trip inutil.
it('selecionar contato salvo abre a conversa sem chamar a Evolution', function () {
    Http::fake();
    [, $u, $c] = cenarioBusca('bs6');
    TenantContext::set($u->tenant_id);
    $joao = contato('+5584911111111', 'Joao');
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'joao')
        ->call('selecionarContato', $joao->id)
        ->assertHasNoErrors()
        ->assertDispatched('abrir-conversa')
        ->assertSet('termo', '');

    Http::assertNothingSent();

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::first()->contact_id)->toBe($joao->id);
});

it('selecionar contato reaproveita a conversa aberta', function () {
    Http::fake();
    [, $u, $c] = cenarioBusca('bs7');
    TenantContext::set($u->tenant_id);
    $joao = contato('+5584911111111', 'Joao');
    $existente = Conversation::create(['channel_id' => $c->id, 'contact_id' => $joao->id]);
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'joao')
        ->call('selecionarContato', $joao->id);

    expect(Conversation::count())->toBe(1)
        ->and(Conversation::first()->id)->toBe($existente->id);
});

it('selecionar contato cuja unica conversa esta arquivada abre uma nova', function () {
    Http::fake();
    [, $u, $c] = cenarioBusca('bs8');
    TenantContext::set($u->tenant_id);
    $joao = contato('+5584911111111', 'Joao');
    $antiga = Conversation::create(['channel_id' => $c->id, 'contact_id' => $joao->id]);
    $antiga->arquivar();
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'joao')
        ->call('selecionarContato', $joao->id);

    expect(Conversation::count())->toBe(2)
        ->and(Conversation::where('status', '!=', Conversation::ARQUIVADA)->count())->toBe(1);
});

it('selecionar contato leva a primeira mensagem quando escrita', function () {
    Queue::fake();
    Http::fake();
    [, $u, $c] = cenarioBusca('bs9');
    TenantContext::set($u->tenant_id);
    $joao = contato('+5584911111111', 'Joao');
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'joao')
        ->set('primeiraMensagem', 'ola, tudo bem?')
        ->call('selecionarContato', $joao->id);

    expect(Message::first()->corpo)->toBe('ola, tudo bem?');
    Queue::assertPushed(SendTextMessage::class);
});

it('nao abre contato de outro tenant nem pelo id direto', function () {
    Http::fake();
    [, $uA, $cA] = cenarioBusca('bsa');
    TenantContext::set($uA->tenant_id);
    $alvo = contato('+5584911111111', 'Alvo');
    TenantContext::forget();

    [, $uB] = cenarioBusca('bsb');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(NewConversation::class)
        ->call('alternar')
        ->call('selecionarContato', $alvo->id);

    expect(Conversation::withoutGlobalScope('tenant')->count())->toBe(0);
});

it('mostra que o contato ja esta em atendimento e por quem', function () {
    [, $u, $c] = cenarioBusca('bsc');
    TenantContext::set($u->tenant_id);
    $joao = contato('+5584911111111', 'Joao');
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $joao->id]);
    $cv->assumir($u);
    TenantContext::forget();

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'joao')
        ->assertViewHas('emAtendimento', fn ($m) => isset($m[$joao->id])
            && $m[$joao->id]['atendente'] === 'Eu');
});

// ------------------------------------------- numero digitado (caminho antigo)

it('numero valido digitado continua abrindo conversa nova', function () {
    Http::fake([
        '*/chat/whatsappNumbers/*' => Http::response([[
            'exists' => true, 'jid' => '5584996143373@s.whatsapp.net', 'number' => '5584996143373',
        ]], 200),
    ]);

    [, $u] = cenarioBusca('bsd');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', '(84) 99614-3373')
        ->call('iniciar')
        ->assertHasNoErrors();

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->telefone_e164)->toBe('+5584996143373')
        ->and(Conversation::count())->toBe(1);
});

it('termo que nao e telefone nem acha contato avisa', function () {
    Http::fake();
    [, $u] = cenarioBusca('bse');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'zzz nao existe')
        ->call('iniciar')
        ->assertHasErrors('termo');

    Http::assertNothingSent();
});

it('sinaliza quando o termo digitado da um telefone valido', function () {
    [, $u] = cenarioBusca('bsf');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', '84 99614-3373')
        ->assertViewHas('telefoneDigitado', '+5584996143373');

    Livewire::actingAs($u)
        ->test(NewConversation::class)
        ->call('alternar')
        ->set('termo', 'joao')
        ->assertViewHas('telefoneDigitado', null);
});
