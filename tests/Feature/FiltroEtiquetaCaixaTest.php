<?php

use App\Livewire\Inbox\{ContactDetails, ConversationList};
use App\Models\{Channel, Contact, Conversation, Tag, Tenant, User};
use App\Services\Etiquetador;
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'flt']);
    TenantContext::set($this->tenant->id);

    $this->user = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Atendente',
        'email' => 'a@flt.test', 'password' => 'segredo123',
    ]);

    $this->canal = Channel::create(['nome' => 'Principal'])->refresh();
    $this->financeiro = Tag::create(['nome' => 'Financeiro', 'cor' => 'verde']);
    $this->suporte = Tag::create(['nome' => 'Suporte', 'cor' => 'azul']);
});

afterEach(fn () => TenantContext::forget());

function conversaCom(string $nome, ?Tag $tag): Conversation
{
    $contato = Contact::create([
        'telefone_e164' => '+5511'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
        'nome' => $nome,
    ]);

    if ($tag) {
        app(Etiquetador::class)->aplicar($contato, [$tag->id], Etiquetador::CHATBOT);
    }

    return Conversation::create([
        'channel_id' => test()->canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);
}

// ============================================= 1. FILTRAR A CAIXA POR ETIQUETA

it('a caixa de entrada filtra por etiqueta', function () {
    conversaCom('Cliente do Financeiro', $this->financeiro);
    conversaCom('Cliente do Suporte', $this->suporte);
    conversaCom('Cliente sem etiqueta', null);

    Livewire::actingAs($this->user)
        ->test(ConversationList::class)
        ->assertSee('Cliente do Financeiro')
        ->assertSee('Cliente do Suporte')
        ->call('filtrarEtiqueta', (string) $this->financeiro->id)
        ->assertSee('Cliente do Financeiro')
        ->assertDontSee('Cliente do Suporte')
        ->assertDontSee('Cliente sem etiqueta');
});

it('os badges respeitam o recorte da etiqueta', function () {
    // Badge que conta conversa fora do recorte manda o atendente procurar o que a
    // lista nem mostra.
    conversaCom('Do Financeiro', $this->financeiro);
    conversaCom('Do Suporte', $this->suporte);

    $tela = Livewire::actingAs($this->user)->test(ConversationList::class);

    expect($tela->viewData('badges')['novos'])->toBe(2);

    $tela->call('filtrarEtiqueta', (string) $this->financeiro->id);

    expect($tela->viewData('badges')['novos'])->toBe(1);
});

it('trocar de etiqueta volta para a primeira pagina', function () {
    // Manter a pagina 3 mostraria um pedaco do meio de uma lista que o atendente
    // nunca viu do comeco.
    conversaCom('Um', $this->financeiro);

    $tela = Livewire::actingAs($this->user)
        ->test(ConversationList::class)
        ->call('carregarMais');

    expect($tela->get('limite'))->toBe(ConversationList::PAGINA * 2);

    $tela->call('filtrarEtiqueta', (string) $this->financeiro->id);

    expect($tela->get('limite'))->toBe(ConversationList::PAGINA);
});

it('etiqueta apagada volta para Todas em vez de esvaziar a caixa', function () {
    conversaCom('Do Financeiro', $this->financeiro);

    $id = $this->suporte->id;
    $this->suporte->delete();

    $tela = Livewire::actingAs($this->user)
        ->test(ConversationList::class)
        ->call('filtrarEtiqueta', (string) $id);

    expect($tela->get('etiqueta'))->toBeNull();
    $tela->assertSee('Do Financeiro');
});

it('caixa vazia por causa do filtro diz o motivo', function () {
    // Lista vazia com filtro ligado e lida como fila vazia, e o atendente vai embora
    // achando que nao ha trabalho.
    conversaCom('Do Suporte', $this->suporte);

    Livewire::actingAs($this->user)
        ->test(ConversationList::class)
        ->call('filtrarEtiqueta', (string) $this->financeiro->id)
        ->assertSee('Nenhuma conversa com a etiqueta')
        ->assertSee('Financeiro');
});

// ======================================== 2. DE ONDE A ETIQUETA VEIO APARECE

it('a frase da origem diz o meio, quem e quando', function () {
    $frase = Etiquetador::comoFoi(Etiquetador::MANUAL, 'Rafael Paulino', '2026-08-04 19:31:00');

    expect($frase)->toBe('Aplicada à mão por Rafael Paulino em 04/08/2026 19:31');
});

it('origem sem pessoa nao inventa autor', function () {
    // Chatbot nao tem nome de usuario, e escrever "por" sem ninguem seria pior.
    expect(Etiquetador::comoFoi(Etiquetador::CHATBOT, null, '2026-08-04 19:31:00'))
        ->toBe('Aplicada pelo chatbot em 04/08/2026 19:31');
});

it('origem desconhecida e dita, nao disfarcada de manual', function () {
    // Linha antiga sem origem existe; afirmar "a mao" seria afirmar o que ninguem
    // verificou.
    expect(Etiquetador::comoFoi(null, null, null))->toBe('Aplicada sem origem registrada');
});

it('o painel mostra que a etiqueta veio do chatbot', function () {
    [$tenant, $u] = [$this->tenant, $this->user];

    $contato = Contact::create(['telefone_e164' => '+5511988887777', 'nome' => 'Rafael']);
    app(Etiquetador::class)->aplicar($contato, [$this->financeiro->id], Etiquetador::CHATBOT);

    $conversa = Conversation::create([
        'channel_id' => $this->canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $conversa->id])
        ->call('alternar')
        ->assertSee('Aplicada pelo chatbot');
});

it('o painel mostra quem aplicou a etiqueta a mao', function () {
    $contato = Contact::create(['telefone_e164' => '+5511988886666', 'nome' => 'Rafael']);

    $conversa = Conversation::create([
        'channel_id' => $this->canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ContactDetails::class, ['conversationId' => $conversa->id])
        ->call('alternar')
        // aplica pela propria tela, que e o caminho de verdade
        ->call('alternarEtiqueta', $this->financeiro->id)
        ->assertSee('Aplicada à mão por Atendente');
});
