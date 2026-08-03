<?php

use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Team, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();
});

afterEach(fn () => TenantContext::forget());

// $desde existe porque dois lotes no mesmo teste repetiriam o telefone e
// bateriam no unique (tenant_id, jid).
function criarConversas(int $quantas, int $channelId, ?int $teamId = null, int $desde = 0): void
{
    for ($i = $desde; $i < $desde + $quantas; $i++) {
        $contato = Contact::create([
            'nome'          => 'Cliente '.$i,
            'telefone_e164' => '+5511900'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'jid'           => '5511900'.str_pad((string) $i, 6, '0', STR_PAD_LEFT).'@s.whatsapp.net',
        ]);

        $conversa = Conversation::abertaOuNova($channelId, $contato->id);
        $conversa->update(['ultima_msg_em' => now()->subMinutes($i), 'team_id' => $teamId]);
    }
}

it('mostra uma pagina e avisa quantas ficaram de fora', function () {
    criarConversas(35, $this->channel->id);

    $tela = Livewire::actingAs($this->usuario)->test(ConversationList::class);

    // O defeito antigo era exatamente isto: com limit(50) fixo a conversa 51
    // desaparecia e nada na tela dizia que ela existia.
    expect($tela->viewData('conversas'))->toHaveCount(ConversationList::PAGINA)
        ->and($tela->viewData('total'))->toBe(35)
        ->and($tela->viewData('restantes'))->toBe(5);
});

it('carregar mais traz o resto e zera o que falta', function () {
    criarConversas(35, $this->channel->id);

    $tela = Livewire::actingAs($this->usuario)
        ->test(ConversationList::class)
        ->call('carregarMais');

    expect($tela->viewData('conversas'))->toHaveCount(35)
        ->and($tela->viewData('restantes'))->toBe(0);
});

it('nao mostra botao quando tudo cabe numa pagina', function () {
    criarConversas(4, $this->channel->id);

    $tela = Livewire::actingAs($this->usuario)->test(ConversationList::class);

    expect($tela->viewData('total'))->toBe(4)
        ->and($tela->viewData('restantes'))->toBe(0);
});

it('trocar de balde volta ao inicio da lista', function () {
    criarConversas(35, $this->channel->id);

    $tela = Livewire::actingAs($this->usuario)
        ->test(ConversationList::class)
        ->call('carregarMais')
        ->assertSet('limite', 60)
        ->call('selecionarBalde', 'meus')
        ->assertSet('limite', ConversationList::PAGINA);

    // Sem reiniciar, o atendente carregaria 300 conversas em Novos e levaria esse
    // peso para todo balde que abrisse depois.
    expect($tela->get('limite'))->toBe(ConversationList::PAGINA);
});

it('buscar volta ao inicio da lista', function () {
    criarConversas(35, $this->channel->id);

    Livewire::actingAs($this->usuario)
        ->test(ConversationList::class)
        ->call('carregarMais')
        ->assertSet('limite', 60)
        ->set('busca', 'Cliente 1')
        ->assertSet('limite', ConversationList::PAGINA);
});

it('o total respeita o filtro de equipe, senao o contador mentiria', function () {
    $suporte = Team::create(['nome' => 'Suporte']);
    $suporte->users()->attach($this->usuario->id, ['papel' => Team::ATENDENTE]);

    criarConversas(5, $this->channel->id, $suporte->id);
    criarConversas(7, $this->channel->id, null, 100);

    $tela = Livewire::actingAs($this->usuario)
        ->test(ConversationList::class)
        ->call('selecionarEquipe', (string) $suporte->id);

    // 5, nao 12: contar fora do recorte diria ao atendente que existe fila que a
    // lista dele nunca vai mostrar.
    expect($tela->viewData('total'))->toBe(5)
        ->and($tela->viewData('restantes'))->toBe(0);
});
