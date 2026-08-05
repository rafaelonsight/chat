<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::fake(['*' => fn () => Http::response(['key' => ['id' => 'A-'.uniqid()]])]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'jan']);
    TenantContext::set($this->tenant->id);

    $this->user = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Atendente',
        'email' => 'a@jan.test', 'password' => 'segredo123',
    ]);

    $this->contato = Contact::create(['telefone_e164' => '+5584996143373', 'nome' => 'Cliente']);
});

afterEach(function () {
    TenantContext::forget();
    Carbon::setTestNow();
});

/** Canal do tipo pedido, com uma conversa e a ultima entrada no instante dado. */
function cenarioJanela(string $tipo, ?Carbon $ultimaEntrada = null): Conversation
{
    $canal = Channel::create(['nome' => 'Canal', 'tipo' => $tipo])->refresh();

    return Conversation::create([
        'channel_id'        => $canal->id,
        'contact_id'        => test()->contato->id,
        'ultima_msg_em'     => now(),
        'ultima_entrada_em' => $ultimaEntrada,
    ]);
}

/** Mensagem nossa na fila, pronta para o job de envio. */
function msgDaJanela(Conversation $conversa): Message
{
    return Message::create([
        'conversation_id' => $conversa->id,
        'channel_id'      => $conversa->channel_id,
        'direcao'         => 'out',
        'tipo'            => 'text',
        'corpo'           => 'oi',
        'status'          => Message::STATUS_QUEUED,
    ]);
}

// ------------------------------------------------- a janela e do TIPO do canal

it('canal Evolution nao tem janela: a pergunta nao se aplica', function () {
    // No Baileys a regra nao existe. Inventar limite ali ensinaria o atendente a
    // ignorar o aviso — inclusive quando ele for verdade.
    $c = cenarioJanela(Channel::EVOLUTION, now()->subDays(30));

    expect($c->channel->exigeJanela())->toBeFalse()
        ->and($c->janelaAte())->toBeNull()
        ->and($c->janelaAberta())->toBeFalse()
        // e mesmo assim pode enviar: e o que importa
        ->and($c->podeEnviarLivre())->toBeTrue();
});

it('canal oficial dentro de 24h: janela aberta e com tempo restante legivel', function () {
    Carbon::setTestNow('2026-08-05 12:00:00');

    $c = cenarioJanela(Channel::META_CLOUD, now()->subHours(20)->subMinutes(40));

    expect($c->janelaAberta())->toBeTrue()
        ->and($c->podeEnviarLivre())->toBeTrue()
        // 24h - 20h40 = 3h20
        ->and($c->janelaRestante())->toBe('3h 20min');
});

it('canal oficial passadas 24h: janela fechada e texto livre barrado', function () {
    $c = cenarioJanela(Channel::META_CLOUD, now()->subHours(24)->subMinute());

    expect($c->janelaAberta())->toBeFalse()
        ->and($c->podeEnviarLivre())->toBeFalse()
        ->and($c->janelaRestante())->toBeNull();
});

it('canal oficial sem o cliente nunca ter falado tambem esta fechado', function () {
    // Sem mensagem do cliente nao existe janela para abrir: no oficial, o primeiro
    // contato do lado da empresa e template, nao texto livre.
    $c = cenarioJanela(Channel::META_CLOUD, null);

    expect($c->janelaAte())->toBeNull()
        ->and($c->podeEnviarLivre())->toBeFalse();
});

it('menos de uma hora restante aparece em minutos', function () {
    Carbon::setTestNow('2026-08-05 12:00:00');

    $c = cenarioJanela(Channel::META_CLOUD, now()->subHours(23)->subMinutes(30));

    expect($c->janelaRestante())->toBe('30min');
});

// --------------------------------------------- o envio barra, nao so a tela

it('o job de envio recusa texto livre com a janela fechada, e nao tenta de novo', function () {
    // Barrar no job e nao so na tela: a mensagem pode ter sido enfileirada com a
    // janela aberta e chegar a vez dela depois de fechada.
    $c = cenarioJanela(Channel::META_CLOUD, now()->subHours(25));
    $m = msgDaJanela($c);

    (new SendTextMessage($m->id))->handle(app(\App\Services\EvolutionService::class));

    $m->refresh();

    expect($m->status)->toBe(Message::STATUS_FAILED)
        ->and($m->erro)->toContain('Janela de 24 horas fechada')
        // nada foi para a Evolution
        ->and($m->external_id)->toBeNull();

    Http::assertNothingSent();
});

it('com a janela aberta, o envio segue normal', function () {
    $c = cenarioJanela(Channel::META_CLOUD, now()->subHours(2));
    $m = msgDaJanela($c);

    (new SendTextMessage($m->id))->handle(app(\App\Services\EvolutionService::class));

    expect($m->refresh()->status)->toBe(Message::STATUS_SENT);
});

it('no canal Evolution o envio nunca e barrado por janela', function () {
    $c = cenarioJanela(Channel::EVOLUTION, now()->subYear());
    $m = msgDaJanela($c);

    (new SendTextMessage($m->id))->handle(app(\App\Services\EvolutionService::class));

    expect($m->refresh()->status)->toBe(Message::STATUS_SENT);
});

// ------------------------------------------------------- o aviso na tela

it('o compositor avisa quando a janela esta fechada', function () {
    $c = cenarioJanela(Channel::META_CLOUD, now()->subHours(30));

    Livewire::actingAs($this->user)
        ->test(MessageComposer::class, ['conversationId' => $c->id])
        ->assertSee('Janela de 24 horas fechada')
        ->assertSee('template aprovado');
});

it('o compositor mostra quanto falta quando a janela esta aberta', function () {
    Carbon::setTestNow('2026-08-05 12:00:00');

    $c = cenarioJanela(Channel::META_CLOUD, now()->subHours(21));

    Livewire::actingAs($this->user)
        ->test(MessageComposer::class, ['conversationId' => $c->id])
        ->assertSee('Janela de atendimento aberta')
        ->assertSee('3h');
});

it('o compositor NAO fala de janela em canal que nao tem janela', function () {
    $c = cenarioJanela(Channel::EVOLUTION, now()->subDays(10));

    Livewire::actingAs($this->user)
        ->test(MessageComposer::class, ['conversationId' => $c->id])
        ->assertDontSee('Janela');
});

// ------------------------------------------------ o resto do tipo de canal

it('grupo nao existe na API oficial, e o modelo diz isso', function () {
    // E o motivo de o hibrido continuar necessario: quem usa grupo de bairro mantem
    // os dois canais lado a lado.
    $evolution = Channel::create(['nome' => 'Nao oficial', 'tipo' => Channel::EVOLUTION]);
    $meta = Channel::create(['nome' => 'Oficial', 'tipo' => Channel::META_CLOUD]);

    expect($evolution->permiteGrupo())->toBeTrue()
        ->and($meta->permiteGrupo())->toBeFalse();
});

it('tipo de canal invalido nao chega a ser gravado', function () {
    // CHECK no banco: tipo errado vira erro na hora, nao comportamento estranho
    // meses depois.
    expect(fn () => Channel::create(['nome' => 'X', 'tipo' => 'telegram']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});
