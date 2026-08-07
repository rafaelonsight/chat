<?php

use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ConversationWindow;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Tres coisas que faltavam no atendimento.
 *
 * 1. AVISO DE COLISAO. Dois atendentes na mesma conversa e nada dizendo. E o erro mais
 *    constrangedor de equipe: o cliente recebe duas respostas diferentes para a mesma
 *    pergunta, com minutos de diferenca. Nao da para impedir — as duas pessoas tem direito de
 *    abrir — mas avisar resolve na pratica.
 *
 * 2. MARCAR COMO NAO LIDA. "Volto nisso depois" nao existia: abriu, leu, zerou o contador, e a
 *    conversa se perdeu no meio da lista.
 *
 * 3. "DIGITANDO…". O WhatsApp mostra e o cliente espera ver. Sem isso ele acha que ninguem
 *    abriu a mensagem dele.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tres']);
    TenantContext::set($this->conta->id);

    $this->joao = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Joao Pedro Silva',
        'email' => 'joao@tres.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'tre',
    ]);

    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->joao->id, 'nao_lidas' => 0, 'ultima_entrada_em' => now(),
    ]);

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $this->conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'bom dia', 'status' => Message::STATUS_DELIVERED,
    ]);

    $this->actingAs($this->joao);
    Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 200)]);
});

// =========================================================== 1. colisao

it('a regra do canal deixa entrar quem e da conta', function () {
    expect(Conversation::visivelPara($this->joao, $this->conversa->id))->toBeTrue();
});

it('a regra do canal BARRA conversa de outra conta', function () {
    // E aqui que o vazamento entre empresas aconteceria: o escopo global protege o banco, mas
    // o tempo real e outro caminho — bastaria assinar o canal da conversa de outra empresa
    // para receber as mensagens dela ao vivo.
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-tres']);
    $canalAlheio = Channel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'nome' => 'X', 'tipo' => 'evolution',
        'status' => 'open', 'instance_name' => 'alh',
    ]);
    $contatoAlheio = Contact::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'nome' => 'Alheio',
        'telefone_e164' => '+5541911112222', 'jid' => '5541911112222@s.whatsapp.net',
    ]);
    $conversaAlheia = Conversation::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'channel_id' => $canalAlheio->id,
        'contact_id' => $contatoAlheio->id, 'status' => Conversation::NOVA,
    ]);

    expect(Conversation::visivelPara($this->joao, $conversaAlheia->id))->toBeFalse();
});

it('sem ninguem logado, ninguem entra', function () {
    expect(Conversation::visivelPara(null, $this->conversa->id))->toBeFalse();
});

it('conversa que nao existe tambem nao', function () {
    expect(Conversation::visivelPara($this->joao, 999999))->toBeFalse();
});

it('so o primeiro nome vai para o canal de presenca', function () {
    // Cada campo que vai para la e lido por todo mundo com a mesma conversa aberta.
    expect($this->joao->primeiroNome())->toBe('Joao');
});

it('o canal usa a regra testavel, e nao uma copia da consulta', function () {
    // Guarda de fiacao, e nao de comportamento: se alguem reescrever a closure e repetir a
    // consulta a mao, ela deixa de ter teste e volta a poder errar em silencio. E o unico
    // jeito honesto que achei de amarrar as duas coisas sem subir uma conexao de websocket.
    $arquivo = file_get_contents(base_path('routes/channels.php'));

    expect($arquivo)->toContain('Conversation::visivelPara($user, $conversationId)')
        ->and($arquivo)->toContain('$user->primeiroNome()');
});

// ==================================================== 2. marcar nao lida

it('marca como nao lida e fecha a conversa', function () {
    // Fechar junto e o ponto: marcar como nao lida com a conversa aberta na frente nao
    // significa nada — o proximo clique zeraria o contador de novo.
    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('marcarNaoLida')
        ->assertSet('conversationId', null)
        ->assertDispatched('fechar-conversa');

    expect($this->conversa->fresh()->nao_lidas)->toBe(1);
});

it('nao encolhe o contador quando chegou mensagem nova no meio', function () {
    // O numero real e maior; poe 1 fixo seria perder informacao que existe.
    $this->conversa->update(['nao_lidas' => 4]);

    $this->conversa->marcarNaoLida();

    expect($this->conversa->fresh()->nao_lidas)->toBe(4);
});

it('a lista tira o destaque da linha depois de marcar', function () {
    Livewire::actingAs($this->joao)->test(ConversationList::class)
        ->set('selecionada', $this->conversa->id)
        ->call('limparSelecao')
        ->assertSet('selecionada', null);
});

it('a conversa volta a aparecer no filtro de nao lidas', function () {
    $this->conversa->marcarNaoLida();

    $conversas = Livewire::actingAs($this->joao)->test(ConversationList::class)
        ->set('equipe', 'sem')->set('balde', 'meus')->set('somenteNaoLidas', true)
        ->viewData('conversas');

    expect($conversas->pluck('id')->all())->toContain($this->conversa->id);
});

// ========================================================== 3. digitando

it('avisa o provedor que alguem esta escrevendo', function () {
    Livewire::actingAs($this->joao)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('digitando', true);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendPresence')
        && $r->data()['presence'] === 'composing');
});

it('avisa que parou', function () {
    Livewire::actingAs($this->joao)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('digitando', false);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendPresence')
        && $r->data()['presence'] === 'paused');
});

it('NAO avisa enquanto se escreve nota interna', function () {
    // A nota nao vai para o cliente. Mostrar "digitando" para ele enquanto o atendente escreve
    // um lembrete interno anuncia uma resposta que nunca vem.
    Livewire::actingAs($this->joao)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->call('digitando', true);

    Http::assertNothingSent();
});

it('o canal oficial nao tenta: la o digitando nao existe', function () {
    // A Meta so mostra o indicador junto do recibo de leitura, preso a um wamid e por 25s.
    // Nao serve para acompanhar alguem escrevendo, e fingir faria aparecer na hora errada.
    $oficial = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Oficial', 'tipo' => 'meta_cloud',
        'status' => 'open', 'meta_phone_number_id' => '1', 'meta_waba_id' => '2', 'meta_token' => 't',
    ]);
    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'C2',
        'telefone_e164' => '+5541988887777', 'jid' => '5541988887777@s.whatsapp.net',
    ]);
    $conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $oficial->id,
        'contact_id' => $contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'ultima_entrada_em' => now(),
    ]);

    Livewire::actingAs($this->joao)
        ->test(MessageComposer::class, ['conversationId' => $conversa->id])
        ->call('digitando', true);

    Http::assertNothingSent();
});

it('provedor recusando o digitando NAO estoura na tela', function () {
    // A pessoa esta no meio de uma frase. Erro por causa de um enfeite atrapalha mais que a
    // falta do enfeite.
    Http::fake(['*' => Http::response(['erro' => 'nao'], 500)]);

    Livewire::actingAs($this->joao)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('digitando', true)
        ->assertOk();
});

it('manda o JID e nao o telefone com mais', function () {
    // Mesma armadilha que estourou no apagar: dentro da chamada o Baileys quer JID.
    Livewire::actingAs($this->joao)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('digitando', true);

    Http::assertSent(fn ($r) => str_contains((string) ($r->data()['number'] ?? ''), '@'));
});
