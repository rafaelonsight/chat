<?php

use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Tag, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * Perguntar como o atendimento terminou, ANTES de arquivar.
 *
 * Etiqueta que so existe se alguem lembrar de aplicar e preenchida em uns 20% dos atendimentos
 * — e 20% nao vira numero em que alguem confia. O fecho e o unico momento em que a pessoa tem
 * a resposta na cabeca.
 *
 * MAS DEIXA PULAR. Obrigar faria o atendente com pressa clicar sempre na primeira opcao, e ai
 * o dado mente de um jeito pior: parece preenchido.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'classificar']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@cls.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cls',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541977770000', 'jid' => '5541977770000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->pessoa->id, 'ultima_msg_em' => now(),
    ]);

    $this->actingAs($this->pessoa);
});

afterEach(fn () => TenantContext::forget());

function etiquetaDeFecho($ctx, string $nome = 'Resolvido'): Tag
{
    return Tag::create([
        'tenant_id' => $ctx->conta->id, 'nome' => $nome,
        'cor' => 'verde', 'contexto' => Tag::CONVERSA,
    ]);
}

it('pergunta antes de arquivar', function () {
    etiquetaDeFecho($this);

    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('finalizar')
        ->assertSet('classificando', true)
        ->assertSee('Como este atendimento terminou?');

    // Nao arquivou ainda: a pergunta vem primeiro.
    expect($this->conversa->refresh()->status)->toBe(Conversation::EM_ATENDIMENTO);
});

it('classifica e encerra num movimento so', function () {
    $tag = etiquetaDeFecho($this);

    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('finalizar')
        ->call('encerrarCom', $tag->id)
        ->assertSet('classificando', false);

    $this->conversa->refresh();

    expect($this->conversa->status)->toBe(Conversation::ARQUIVADA)
        ->and($this->conversa->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

it('deixa sair sem classificar', function () {
    // Obrigar faria o atendente com pressa clicar sempre na primeira opcao.
    etiquetaDeFecho($this);

    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('finalizar')
        ->call('encerrar');

    $this->conversa->refresh();

    expect($this->conversa->status)->toBe(Conversation::ARQUIVADA)
        ->and($this->conversa->tags()->count())->toBe(0);
});

it('sem etiqueta de conversa cadastrada, nem pergunta', function () {
    // Perguntar com a lista vazia so atrapalha quem ainda nao configurou nada.
    Tag::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente VIP',
        'cor' => 'ambar', 'contexto' => Tag::CONTATO,
    ]);

    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('finalizar')
        ->assertSet('classificando', false);

    expect($this->conversa->refresh()->status)->toBe(Conversation::ARQUIVADA);
});

it('desistir da pergunta nao arquiva', function () {
    etiquetaDeFecho($this);

    Livewire::actingAs($this->pessoa)->test(ConversationWindow::class)
        ->call('abrir', $this->conversa->id)
        ->call('finalizar')
        ->set('classificando', false);

    expect($this->conversa->refresh()->status)->toBe(Conversation::EM_ATENDIMENTO);
});
