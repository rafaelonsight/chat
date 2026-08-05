<?php

use App\Jobs\SendTextMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant};
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);
    $this->channel = Channel::create(['nome' => 'C'])->refresh();
    $this->contact = Contact::create(['telefone_e164' => '+5511999998888']);
    $this->conversation = Conversation::create([
        'channel_id' => $this->channel->id,
        'contact_id' => $this->contact->id,
    ]);
});

afterEach(fn () => TenantContext::forget());

function mensagemNaFila(): Message
{
    return Message::create([
        'conversation_id' => test()->conversation->id,
        'channel_id'      => test()->channel->id,
        'direcao'         => 'out',
        'corpo'           => 'oi',
        'status'          => Message::STATUS_QUEUED,
    ]);
}

it('envia e marca como sent guardando o external_id', function () {
    Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'OUT1']], 201)]);

    $m = mensagemNaFila();
    (new SendTextMessage($m->id))->handle(app(\App\Services\Canais\Enviadores::class));

    $m->refresh();
    expect($m->status)->toBe(Message::STATUS_SENT)
        ->and($m->external_id)->toBe('OUT1')
        ->and($m->enviada_em)->not->toBeNull()
        ->and($m->erro)->toBeNull();

    Http::assertSent(fn ($r) => $r['number'] === '+5511999998888' && $r['text'] === 'oi');
});

it('usa a instancia do canal da conversa', function () {
    Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 201)]);

    $m = mensagemNaFila();
    (new SendTextMessage($m->id))->handle(app(\App\Services\Canais\Enviadores::class));

    Http::assertSent(fn ($r) => str_contains($r->url(), '/message/sendText/'.$this->channel->instance_name));
});

it('marca como failed quando a Evolution devolve erro', function () {
    Http::fake(['*/message/sendText/*' => Http::response(['message' => 'instancia fora'], 500)]);

    $m = mensagemNaFila();

    try {
        (new SendTextMessage($m->id))->handle(app(\App\Services\Canais\Enviadores::class));
    } catch (\Throwable) {
        // relanca de proposito para o Horizon registrar e tentar de novo
    }

    expect($m->refresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->erro)->not->toBeNull();
});

it('nao reenvia mensagem que ja saiu', function () {
    Http::fake(['*' => Http::response(['key' => ['id' => 'Y']], 201)]);

    $m = mensagemNaFila();
    $m->update(['status' => Message::STATUS_SENT, 'external_id' => 'JA-FOI']);

    (new SendTextMessage($m->id))->handle(app(\App\Services\Canais\Enviadores::class));

    Http::assertNothingSent();
    expect($m->refresh()->external_id)->toBe('JA-FOI');
});
