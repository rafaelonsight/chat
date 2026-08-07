<?php

use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, ConversationEvent, Team, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * Passar a conversa para uma PESSOA.
 *
 * So dava para transferir para EQUIPE, e as duas coisas nao sao a mesma:
 *
 *   equipe  -> devolve a conversa para a fila. Perde o dono, volta a ser Nova, e quem estiver
 *              livre pega.
 *   pessoa  -> ja escolhe o dono. Entra em atendimento no nome dela, e nao fica parada em
 *              Novos esperando alguem notar.
 *
 * Sem a segunda, "passa para a Marina, que atendeu ele semana passada" virava mandar para a
 * equipe inteira e torcer para a Marina pegar antes dos outros.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'passar']);
    TenantContext::set($this->conta->id);

    $this->joao = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Joao',
        'email' => 'joao@passar.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->marina = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Marina',
        'email' => 'marina@passar.test', 'password' => 'segredo123', 'admin' => false,
    ]);

    $this->equipe = Team::create(['tenant_id' => $this->conta->id, 'nome' => 'Suporte', 'ativa' => true]);

    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'pas',
    ]);

    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $canal->id,
        'contact_id' => $contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->joao->id, 'team_id' => $this->equipe->id,
    ]);

    $this->actingAs($this->joao);
});

it('passa a conversa para outra pessoa e ela ja fica em atendimento', function () {
    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('passarPara', $this->marina->id)
        ->assertHasNoErrors();

    $c = $this->conversa->fresh();

    expect($c->atendente_id)->toBe($this->marina->id)
        // Nao volta para a fila: quem recebe ja e a dona. Cair em Novos seria o mesmo que
        // ter transferido para a equipe.
        ->and($c->status)->toBe(Conversation::EM_ATENDIMENTO);
});

it('nao troca a equipe junto', function () {
    // Se trocasse, o numero do relatorio por equipe mudaria sem ninguem ter pedido: uma
    // conversa do Suporte viraria de Vendas so porque quem atende mudou de mesa.
    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('passarPara', $this->marina->id);

    expect($this->conversa->fresh()->team_id)->toBe($this->equipe->id);
});

it('registra quem passou para quem, na linha do tempo', function () {
    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('passarPara', $this->marina->id);

    $evento = ConversationEvent::where('conversation_id', $this->conversa->id)
        ->where('tipo', ConversationEvent::TRANSFERENCIA)->latest('id')->first();

    expect($evento)->not->toBeNull()
        ->and($evento->descricao)->toBe('Passada de Joao para Marina')
        ->and($evento->user_id)->toBe($this->joao->id);
});

it('nao passa para alguem de outra conta', function () {
    // A pior falha possivel aqui: a pessoa receberia a conversa inteira de um cliente que
    // nao e dela.
    $outraConta = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-passar']);
    $estranho = User::create([
        'tenant_id' => $outraConta->id, 'name' => 'Estranho',
        'email' => 'estranho@outra.test', 'password' => 'segredo123',
    ]);

    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('passarPara', $estranho->id)
        ->assertHasErrors('transferir');

    expect($this->conversa->fresh()->atendente_id)->toBe($this->joao->id);
});

it('o modelo tambem recusa, e nao so a tela', function () {
    $outraConta = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-modelo']);
    $estranho = User::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outraConta->id, 'name' => 'Estranho',
        'email' => 'estranho2@outra.test', 'password' => 'segredo123',
    ]);

    expect($this->conversa->passarPara($estranho))->toBeFalse()
        ->and($this->conversa->fresh()->atendente_id)->toBe($this->joao->id);
});

it('o menu nao oferece passar para quem ja esta com a conversa', function () {
    $pessoas = Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->viewData('pessoas');

    expect($pessoas->pluck('id')->all())->not->toContain($this->joao->id)
        ->and($pessoas->pluck('id')->all())->toContain($this->marina->id);
});

it('conversa sem dono oferece todo mundo', function () {
    $this->conversa->update(['atendente_id' => null, 'status' => Conversation::NOVA]);

    $pessoas = Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->viewData('pessoas');

    expect($pessoas->pluck('id')->all())->toContain($this->joao->id, $this->marina->id);
});

it('transferir para EQUIPE continua devolvendo para a fila', function () {
    // O comportamento antigo nao pode ter mudado de carona.
    $vendas = Team::create(['tenant_id' => $this->conta->id, 'nome' => 'Vendas', 'ativa' => true]);

    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('transferir', $vendas->id);

    $c = $this->conversa->fresh();

    expect($c->team_id)->toBe($vendas->id)
        ->and($c->atendente_id)->toBeNull()
        ->and($c->status)->toBe(Conversation::NOVA);
});
