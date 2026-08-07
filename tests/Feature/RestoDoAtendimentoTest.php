<?php

use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * O que faltava no atendimento: busca dentro da conversa, encaminhar, tempo de espera e fixar.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'resto']);
    TenantContext::set($this->conta->id);

    $this->joao = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Joao',
        'email' => 'joao@resto.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'res',
    ]);

    $this->cliente = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->cliente->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->joao->id, 'ultima_msg_em' => now(), 'ultima_entrada_em' => now(),
    ]);

    $this->actingAs($this->joao);
    Queue::fake();
});

function msg($ctx, ?string $corpo, string $direcao = 'in', array $extra = []): Message
{
    return Message::create(array_merge([
        'tenant_id' => $ctx->conta->id, 'conversation_id' => $ctx->conversa->id,
        'channel_id' => $ctx->canal->id, 'direcao' => $direcao, 'tipo' => 'text',
        'corpo' => $corpo, 'status' => Message::STATUS_DELIVERED,
    ], $extra));
}

// ================================================== busca dentro da conversa

it('acha a mensagem pelo texto', function () {
    msg($this, 'bom dia');
    msg($this, 'segue o orçamento de 500 reais');
    msg($this, 'obrigado');

    $mensagens = Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->set('buscaNaConversa', 'orçamento')
        ->viewData('mensagens');

    expect($mensagens)->toHaveCount(1)
        ->and($mensagens->first()->corpo)->toContain('orçamento');
});

it('acha tambem na transcricao do audio e na legenda', function () {
    // Nao achar em audio transcrito faria a busca parecer quebrada justamente para quem usa
    // audio — que e a maioria dos clientes.
    msg($this, null, 'in', ['tipo' => 'audio', 'media_path' => 'a.ogg', 'transcricao' => 'quero cancelar o pedido']);
    msg($this, null, 'in', ['tipo' => 'image', 'media_path' => 'b.jpg', 'legenda' => 'foto do cancelamento']);
    msg($this, 'bom dia');

    $mensagens = Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->set('buscaNaConversa', 'cancel')
        ->viewData('mensagens');

    expect($mensagens)->toHaveCount(2);
});

it('busca sem resultado diz que nao achou, em vez de ficar vazia', function () {
    msg($this, 'bom dia');

    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->set('buscaNaConversa', 'jacaré')
        ->assertSee('Nenhuma mensagem desta conversa contém');
});

it('limpar a busca traz tudo de volta', function () {
    msg($this, 'bom dia');
    msg($this, 'boa tarde');

    $tela = Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->set('buscaNaConversa', 'bom');

    expect($tela->viewData('mensagens'))->toHaveCount(1);

    $tela->call('limparBusca');

    expect($tela->viewData('mensagens'))->toHaveCount(2)
        ->and($tela->get('buscaNaConversa'))->toBe('');
});

// ============================================================== encaminhar

it('encaminha o texto para outro contato e abre a conversa dele', function () {
    $original = msg($this, 'o boleto vence dia 10');

    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Maria Financeiro',
        'telefone_e164' => '+5541988887777', 'jid' => '5541988887777@s.whatsapp.net',
    ]);

    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('encaminhar', $original->id, $outro->id)
        ->assertHasNoErrors()
        ->assertDispatched('abrir-conversa');

    $nova = Message::where('direcao', 'out')->latest('id')->first();

    expect($nova->corpo)->toBe('o boleto vence dia 10')
        ->and($nova->conversation->contact_id)->toBe($outro->id);

    Queue::assertPushed(App\Jobs\SendTextMessage::class);
});

it('COPIA o arquivo em vez de apontar para o mesmo', function () {
    // Duas mensagens no mesmo caminho pareceriam economia ate o dia em que o primeiro contato
    // pedisse a exclusao dos dados pela LGPD: o arquivo sumiria e a mensagem encaminhada, que
    // e de OUTRA pessoa e nao foi pedida, quebraria junto.
    Storage::disk('local')->put('media/original.pdf', 'conteudo');

    $original = msg($this, null, 'in', [
        'tipo' => 'document', 'media_path' => 'media/original.pdf', 'media_nome' => 'boleto.pdf',
    ]);

    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Maria',
        'telefone_e164' => '+5541988886666', 'jid' => '5541988886666@s.whatsapp.net',
    ]);

    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('encaminhar', $original->id, $outro->id);

    $nova = Message::where('direcao', 'out')->latest('id')->first();

    expect($nova->media_path)->not->toBe('media/original.pdf')
        ->and(Storage::disk('local')->exists($nova->media_path))->toBeTrue()
        ->and(Storage::disk('local')->exists('media/original.pdf'))->toBeTrue()
        ->and($nova->media_nome)->toBe('boleto.pdf');

    Queue::assertPushed(App\Jobs\SendMediaMessage::class);
});

it('nao encaminha mensagem apagada', function () {
    $apagada = msg($this, 'valor errado', 'out', ['apagada_em' => now()]);

    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Maria',
        'telefone_e164' => '+5541988885555', 'jid' => '5541988885555@s.whatsapp.net',
    ]);

    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('encaminhar', $apagada->id, $outro->id)
        ->assertHasErrors('encaminhar');

    Queue::assertNothingPushed();
});

it('nao encaminha mensagem de outra conversa', function () {
    $outroContato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Terceiro',
        'telefone_e164' => '+5541977776666', 'jid' => '5541977776666@s.whatsapp.net',
    ]);
    $outraConversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outroContato->id, 'status' => Conversation::NOVA,
    ]);
    $alheia = Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $outraConversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'segredo', 'status' => Message::STATUS_DELIVERED,
    ]);

    Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('encaminhar', $alheia->id, $outroContato->id);

    Queue::assertNothingPushed();
});

// ========================================================= tempo de espera

it('conta desde a ultima mensagem do CLIENTE', function () {
    msg($this, 'alguem?');
    $this->conversa->update(['ultima_entrada_em' => now()->subMinutes(90)]);

    expect(ConversationList::esperandoHa($this->conversa->fresh()))->toBe(90);
});

it('nao mostra espera quando a ultima palavra foi nossa', function () {
    // A bola esta com o cliente. Marcar atraso do atendente aqui seria inventar culpa.
    msg($this, 'ja respondi', 'out');
    $this->conversa->update(['ultima_entrada_em' => now()->subMinutes(90)]);

    expect(ConversationList::esperandoHa($this->conversa->fresh()))->toBeNull();
});

it('conversa que o cliente nunca escreveu nao espera nada', function () {
    $this->conversa->update(['ultima_entrada_em' => null]);

    expect(ConversationList::esperandoHa($this->conversa->fresh()))->toBeNull();
});

// ================================================================== fixar

it('fixa e solta', function () {
    $tela = Livewire::actingAs($this->joao)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id]);

    $tela->call('alternarFixada');
    expect($this->conversa->fresh()->fixadaPara($this->joao))->toBeTrue();

    $tela->call('alternarFixada');
    expect($this->conversa->fresh()->fixada_em)->toBeNull();
});

it('a fixada sobe para o topo da lista', function () {
    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Mais recente',
        'telefone_e164' => '+5541966665555', 'jid' => '5541966665555@s.whatsapp.net',
    ]);
    $maisNova = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outro->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->joao->id, 'ultima_msg_em' => now()->addMinute(),
    ]);

    $this->conversa->fixarPara($this->joao);

    $conversas = Livewire::actingAs($this->joao)->test(ConversationList::class)
        ->set('equipe', 'sem')->set('balde', 'meus')
        ->viewData('conversas');

    expect($conversas->first()->id)->toBe($this->conversa->id)
        ->and($conversas->last()->id)->toBe($maisNova->id);
});

it('a conversa que EU fixei nao sobe na lista do outro atendente', function () {
    // Se fosse da conta, quem fixasse o caso dele empurraria a lista de todo mundo — e o
    // recurso seria desligado na primeira semana.
    $maria = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Maria',
        'email' => 'maria@resto.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $outro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Recente',
        'telefone_e164' => '+5541955554444', 'jid' => '5541955554444@s.whatsapp.net',
    ]);
    // Em "Novos" a lista vai por ordem de CHEGADA — fila se atende assim, e o codigo esta
    // certo; eu e que supus o contrario. Entao a mais antiga vem primeiro naturalmente, e o
    // alfinete do Joao e o unico motivo pelo qual a dele poderia furar essa ordem.
    $maisAntiga = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $outro->id, 'status' => Conversation::NOVA,
        'ultima_msg_em' => now()->subMinutes(10),
    ]);

    $this->conversa->fixarPara($this->joao);
    $this->conversa->update(['atendente_id' => null, 'status' => Conversation::NOVA]);

    // Para o Joao, a fixada dele fura a fila.
    $doJoao = Livewire::actingAs($this->joao)->test(ConversationList::class)
        ->set('equipe', 'sem')->set('balde', 'novos')
        ->viewData('conversas');

    expect($doJoao->first()->id)->toBe($this->conversa->id);

    $conversas = Livewire::actingAs($maria)->test(ConversationList::class)
        ->set('equipe', 'sem')->set('balde', 'novos')
        ->viewData('conversas');

    // Para a Maria, nao: a fila dela segue a ordem de chegada.
    expect($conversas->first()->id)->toBe($maisAntiga->id);
});
