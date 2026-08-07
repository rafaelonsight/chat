<?php

use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Assinar as mensagens com o nome de quem respondeu.
 *
 * O cliente ve UM numero, nao uma equipe. Sem assinatura, tres pessoas diferentes respondendo
 * parecem a mesma pessoa mudando de ideia — e quando o cliente cobra "mas voce disse outra
 * coisa ontem", ninguem sabe quem disse.
 *
 * O NOME ENTRA NO CORPO, e nao num campo separado. E o que o cliente recebe, e o historico
 * daqui tem de mostrar exatamente o texto que chegou la. Guardar em outro campo faria a bolha
 * e o aparelho do cliente contarem historias diferentes.
 *
 * NASCE DESLIGADA: ligar por padrao mudaria, na primeira atualizacao, o texto que todo cliente
 * de todo mundo recebe, sem ninguem ter pedido.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'assinatura']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Ana Paula Rodrigues',
        'email' => 'ana@assinatura.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'ass',
    ]);

    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $canal->id,
        'contact_id' => $contato->id, 'status' => 'aberta', 'ultima_entrada_em' => now(),
    ]);

    $this->actingAs($this->pessoa);
    Queue::fake();
    Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 200)]);
});

function ultimaEnviada(): ?Message
{
    return Message::where('direcao', 'out')->latest('id')->first();
}

it('nasce desligada: nao mexe no texto de ninguem sem pedirem', function () {
    expect($this->conta->fresh()->assinatura_ativa)->toBeFalse();

    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->set('corpo', 'Bom dia')
        ->call('enviar');

    expect(ultimaEnviada()->corpo)->toBe('Bom dia');
});

it('assina com o PRIMEIRO nome, em negrito, na primeira linha', function () {
    // Primeiro nome so: "Ana Paula Rodrigues" ocupando uma linha inteira antes de cada
    // resposta cansa em cinco mensagens.
    $this->conta->update(['assinatura_ativa' => true]);

    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->set('corpo', 'Bom dia')
        ->call('enviar');

    expect(ultimaEnviada()->corpo)->toBe("*Ana*\nBom dia");
});

it('nota interna NAO assina', function () {
    // Escrevi este teste procurando a nota em Message e ele estourou: nota interna nem e
    // mensagem, e ConversationEvent. Ou seja, a assinatura ja nao a alcanca por construcao —
    // mas o teste fica, porque no dia em que alguem transformar nota em mensagem "para
    // aparecer no historico junto", ela nao pode sair assinada como se o cliente fosse ler.
    $this->conta->update(['assinatura_ativa' => true]);

    Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->set('corpo', 'cliente ja reclamou disso antes')
        ->call('enviar');

    $nota = App\Models\ConversationEvent::where('conversation_id', $this->conversa->id)
        ->where('tipo', App\Models\ConversationEvent::NOTA)->latest('id')->first();

    expect($nota)->not->toBeNull()
        ->and($nota->descricao)->not->toContain('*Ana*')
        // e nenhuma mensagem de saida nasceu: nota nao vai para o cliente
        ->and(Message::where('direcao', 'out')->count())->toBe(0);
});

it('a assinatura vale para a legenda do anexo tambem', function () {
    // Senao o cliente recebe foto de um jeito e texto de outro, do mesmo atendimento.
    $this->conta->update(['assinatura_ativa' => true]);

    expect((new ReflectionMethod(MessageComposer::class, 'assinar'))->isPrivate())->toBeTrue();

    $componente = Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->instance();

    $assinar = (new ReflectionMethod($componente, 'assinar'));
    $assinar->setAccessible(true);

    expect($assinar->invoke($componente, 'segue o comprovante'))->toBe("*Ana*\nsegue o comprovante");
});

it('nao assina texto vazio, para nao mandar so o nome', function () {
    $this->conta->update(['assinatura_ativa' => true]);

    $componente = Livewire::actingAs($this->pessoa)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->instance();

    $assinar = (new ReflectionMethod($componente, 'assinar'));
    $assinar->setAccessible(true);

    expect($assinar->invoke($componente, ''))->toBe('');
});

it('a conta de outro cliente nao herda a assinatura desta', function () {
    // O escopo de conta vale aqui como em tudo: ligar na minha conta nao pode assinar as
    // mensagens de outra empresa.
    $this->conta->update(['assinatura_ativa' => true]);

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-ass']);

    expect($outra->assinatura_ativa)->toBeFalse();
});

it('a tela de configuracao liga e desliga', function () {
    Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\HorarioAtendimento::class)
        ->assertSet('assinatura_ativa', false)
        ->set('assinatura_ativa', true)
        ->call('salvar');

    expect($this->conta->fresh()->assinatura_ativa)->toBeTrue();
});
