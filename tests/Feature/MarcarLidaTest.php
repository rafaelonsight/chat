<?php

use App\Jobs\MarcarLidaNoWhatsapp;
use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();

    if (! $this->channel->instance_name) {
        $this->channel->forceFill(['instance_name' => 'inst-teste'])->save();
    }

    $this->contact = Contact::create([
        'nome'          => 'Cliente',
        'telefone_e164' => '+5511999998888',
        'jid'           => '5511999998888@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::abertaOuNova($this->channel->id, $this->contact->id);
});

afterEach(fn () => TenantContext::forget());

function mensagemDoCliente(int $conversaId, int $canalId, string $externalId): Message
{
    return Message::create([
        'conversation_id' => $conversaId,
        'channel_id'      => $canalId,
        'direcao'         => 'in',
        'tipo'            => 'text',
        'corpo'           => 'oi',
        'external_id'     => $externalId,
    ]);
}

it('avisa o WhatsApp e registra quando marcou', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $m1 = mensagemDoCliente($this->conversa->id, $this->channel->id, 'MSG1');
    $m2 = mensagemDoCliente($this->conversa->id, $this->channel->id, 'MSG2');

    (new MarcarLidaNoWhatsapp($this->conversa->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(function ($requisicao) {
        $corpo = $requisicao->data();

        return str_contains($requisicao->url(), '/chat/markMessageAsRead/')
            && count($corpo['readMessages']) === 2
            && $corpo['readMessages'][0]['remoteJid'] === '5511999998888@s.whatsapp.net'
            && $corpo['readMessages'][0]['fromMe'] === false
            && $corpo['readMessages'][0]['id'] === 'MSG1';
    });

    expect($m1->fresh()->lida_em)->not->toBeNull()
        ->and($m2->fresh()->lida_em)->not->toBeNull();
});

it('nao remarca o que ja foi marcado', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    mensagemDoCliente($this->conversa->id, $this->channel->id, 'MSG1');

    $job = new MarcarLidaNoWhatsapp($this->conversa->id);
    $job->handle(app(App\Services\Canais\Enviadores::class));
    $job->handle(app(App\Services\Canais\Enviadores::class));

    // Duas aberturas da mesma conversa nao devem gerar duas chamadas: seria
    // trafego a mais na Evolution a cada clique do atendente.
    Http::assertSentCount(1);
});

it('nunca marca mensagem nossa nem mensagem sem id do WhatsApp', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $nossa = Message::create([
        'conversation_id' => $this->conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'out',
        'tipo'            => 'text',
        'corpo'           => 'resposta',
        'external_id'     => 'MSGOUT',
    ]);

    $semId = Message::create([
        'conversation_id' => $this->conversa->id,
        'channel_id'      => $this->channel->id,
        'direcao'         => 'in',
        'tipo'            => 'text',
        'corpo'           => 'sem id',
    ]);

    (new MarcarLidaNoWhatsapp($this->conversa->id))->handle(app(App\Services\Canais\Enviadores::class));

    // Sem mensagem marcavel, nem chamada deve sair.
    Http::assertNothingSent();

    expect($nossa->fresh()->lida_em)->toBeNull()
        ->and($semId->fresh()->lida_em)->toBeNull();
});

it('se a Evolution falhar, deixa sem marcar para tentar de novo', function () {
    Http::fake(['*' => Http::response(['erro' => 'fora do ar'], 500)]);

    $m = mensagemDoCliente($this->conversa->id, $this->channel->id, 'MSG1');

    expect(fn () => (new MarcarLidaNoWhatsapp($this->conversa->id))
        ->handle(app(App\Services\Canais\Enviadores::class)))
        ->toThrow(Illuminate\Http\Client\RequestException::class);

    // Marcar antes de confirmar mentiria: o cliente continuaria com dois tiques
    // cinza e nos acreditariamos que avisamos.
    expect($m->fresh()->lida_em)->toBeNull();
});

it('no canal oficial marca lida pela Meta, e nao pela Evolution', function () {
    // O bug que motivou isto: o atendente abria a conversa oficial, o job ia falar com a
    // Evolution e tomava 404. Resultado pratico — o cliente nunca via a mensagem como
    // lida, e cada clique deixava um job falhado.
    Http::fake(['*' => Http::response(['success' => true])]);

    $oficial = Channel::create([
        'nome'                 => 'Oficial',
        'tipo'                 => Channel::META_CLOUD,
        'meta_phone_number_id' => '1235849066282498',
    ])->refresh();

    $conversa = Conversation::abertaOuNova($oficial->id, $this->contact->id);
    $primeira = mensagemDoCliente($conversa->id, $oficial->id, 'wamid.A');
    $ultima   = mensagemDoCliente($conversa->id, $oficial->id, 'wamid.B');

    (new MarcarLidaNoWhatsapp($conversa->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertSent(function ($r) {
        $c = $r->data();

        return str_contains($r->url(), 'graph.facebook.com')
            && str_contains($r->url(), '1235849066282498/messages')
            && $c['status'] === 'read'
            && $c['message_id'] === 'wamid.B';
    });

    // UMA chamada, com a mais nova: o WhatsApp marca as anteriores junto.
    Http::assertSentCount(1);

    expect($primeira->fresh()->lida_em)->not->toBeNull()
        ->and($ultima->fresh()->lida_em)->not->toBeNull();
});

it('nunca chama a Evolution por uma conversa do canal oficial', function () {
    // Trava a regressao pelo outro lado: nao basta chamar a Meta, tem de NAO chamar a
    // Evolution. Era esse o 404.
    Http::fake(['*' => Http::response(['success' => true])]);

    $oficial = Channel::create([
        'nome'                 => 'Oficial',
        'tipo'                 => Channel::META_CLOUD,
        'meta_phone_number_id' => '999',
    ])->refresh();

    $conversa = Conversation::abertaOuNova($oficial->id, $this->contact->id);
    mensagemDoCliente($conversa->id, $oficial->id, 'wamid.A');

    (new MarcarLidaNoWhatsapp($conversa->id))->handle(app(App\Services\Canais\Enviadores::class));

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'markMessageAsRead'));
});

it('abrir a conversa na tela enfileira o aviso, sem travar a interface', function () {
    Queue::fake();

    $usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::actingAs($usuario)
        ->test(ConversationList::class)
        ->call('selecionar', $this->conversa->id);

    Queue::assertPushed(MarcarLidaNoWhatsapp::class, fn ($job) => $job->conversationId === $this->conversa->id);
});
