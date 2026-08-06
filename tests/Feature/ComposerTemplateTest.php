<?php

use App\Jobs\SendTemplateMessage;
use App\Livewire\Inbox\MessageComposer;
use App\Models\{Channel, Contact, Conversation, Message, MetaTemplate, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * O campo de mensagem quando a janela de 24h fecha.
 *
 * A regra que isto protege: fora da janela, texto livre e recusado pela Meta. Deixar o
 * atendente digitar ali seria deixa-lo trabalhar para receber erro — e ele culparia o
 * OnChat, com razao.
 */

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'comp']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@comp.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111', 'meta_waba_id' => '362',
    ])->refresh();

    $this->contato = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5541984919939']);
    $this->conversa = Conversation::abertaOuNova($this->canal->id, $this->contato->id);

    $this->modelo = MetaTemplate::create([
        'meta_waba_id' => '362', 'meta_id' => '1',
        'nome' => 'aviso_de_fatura', 'idioma' => 'pt_BR', 'categoria' => 'UTILITY',
        'status' => MetaTemplate::APROVADO,
        'corpo' => 'Prezado {{1}}, sua fatura {{2}} vence hoje.',
        'variaveis' => 2, 'suportado' => true,
    ]);

    Queue::fake();
});

afterEach(fn () => TenantContext::forget());

/** Fecha a janela: ultima mensagem do cliente ha mais de 24h. */
function fecharJanela(): void
{
    test()->conversa->forceFill(['ultima_entrada_em' => now()->subHours(30)])->save();
}

it('com a janela ABERTA nao oferece template, porque texto livre e melhor e nao custa', function () {
    $this->conversa->forceFill(['ultima_entrada_em' => now()])->save();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->assertViewHas('templatesDisponiveis', fn ($t) => $t->isEmpty())
        ->assertSee('Janela de atendimento aberta');
});

it('com a janela FECHADA oferece os templates enviaveis', function () {
    fecharJanela();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->assertViewHas('templatesDisponiveis', fn ($t) => $t->count() === 1)
        ->assertSee('aviso_de_fatura')
        ->assertSee('cobrado pela Meta');
});

it('nao oferece template em analise nem de formato sem suporte', function () {
    fecharJanela();

    MetaTemplate::create([
        'meta_waba_id' => '362', 'nome' => 'em_analise', 'idioma' => 'pt_BR',
        'status' => 'PENDING', 'corpo' => 'x', 'variaveis' => 0, 'suportado' => true,
    ]);

    MetaTemplate::create([
        'meta_waba_id' => '362', 'nome' => 'com_carrossel', 'idioma' => 'pt_BR',
        'status' => MetaTemplate::APROVADO, 'corpo' => 'x', 'variaveis' => 0,
        'suportado' => false, 'motivo_nao_suportado' => 'carrossel',
    ]);

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->assertViewHas('templatesDisponiveis', fn ($t) => $t->count() === 1)
        ->assertDontSee('em_analise')
        ->assertDontSee('com_carrossel');
});

it('nao oferece template de outra conta do WhatsApp', function () {
    // Com varios clientes, oferecer o template de um na conversa de outro seria vazamento
    // entre contas — e o envio falharia, porque o template nao existe naquela WABA.
    fecharJanela();

    MetaTemplate::create([
        'meta_waba_id' => 'OUTRA', 'nome' => 'de_outro_cliente', 'idioma' => 'pt_BR',
        'status' => MetaTemplate::APROVADO, 'corpo' => 'x', 'variaveis' => 0, 'suportado' => true,
    ]);

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->assertDontSee('de_outro_cliente');
});

it('escolher o template abre uma caixa por variavel', function () {
    fecharJanela();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('escolherTemplate', $this->modelo->id)
        ->assertSet('templateId', $this->modelo->id)
        ->assertSet('valoresTemplate', ['', '']);
});

it('mostra a previa do que o cliente vai ler antes de enviar', function () {
    // Sem previa, conferir o texto exigiria gastar um envio cobrado.
    fecharJanela();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('escolherTemplate', $this->modelo->id)
        ->set('valoresTemplate', ['Rafael', '12345'])
        ->assertSee('Prezado Rafael, sua fatura 12345 vence hoje.');
});

it('envia o template e guarda no historico o texto que o cliente leu', function () {
    fecharJanela();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('escolherTemplate', $this->modelo->id)
        ->set('valoresTemplate', ['Rafael', '12345'])
        ->call('enviarTemplate')
        ->assertHasNoErrors()
        ->assertSet('templateId', null);

    $mensagem = Message::where('conversation_id', $this->conversa->id)->firstOrFail();

    expect($mensagem->tipo)->toBe('template')
        ->and($mensagem->direcao)->toBe('out')
        ->and($mensagem->status)->toBe(Message::STATUS_QUEUED)
        ->and($mensagem->corpo)->toBe('Prezado Rafael, sua fatura 12345 vence hoje.');

    Queue::assertPushed(
        SendTemplateMessage::class,
        fn ($job) => $job->messageId === $mensagem->id
            && $job->templateId === $this->modelo->id
            && $job->valores === ['Rafael', '12345'],
    );
});

it('recusa valor em branco dizendo qual, sem criar mensagem', function () {
    fecharJanela();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('escolherTemplate', $this->modelo->id)
        ->set('valoresTemplate', ['Rafael', '   '])
        ->call('enviarTemplate')
        ->assertHasErrors('template');

    expect(Message::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('escolher template que nao pode ser enviado nao faz nada', function () {
    fecharJanela();

    $semSuporte = MetaTemplate::create([
        'meta_waba_id' => '362', 'nome' => 'com_imagem', 'idioma' => 'pt_BR',
        'status' => MetaTemplate::APROVADO, 'corpo' => 'x', 'variaveis' => 0,
        'suportado' => false, 'motivo_nao_suportado' => 'cabeçalho de image',
    ]);

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('escolherTemplate', $semSuporte->id)
        ->assertSet('templateId', null);
});

it('nota interna continua possivel com a janela fechada', function () {
    // Nota nao vai para o WhatsApp, entao a janela nao tem nada a ver com ela. Bloquear a
    // nota junto com o texto livre tiraria do atendente o registro do atendimento.
    fecharJanela();

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $this->conversa->id])
        ->call('alternarNota')
        ->assertSet('nota', true)
        ->assertSee('Nota interna');
});

it('canal sem janela nao mostra template nenhum', function () {
    // Na Evolution nao existe janela nem template: mostrar seria inventar restricao.
    $evolution = Channel::create(['nome' => 'Pessoal', 'tipo' => Channel::EVOLUTION])->refresh();
    $conversa = Conversation::abertaOuNova($evolution->id, $this->contato->id);

    Livewire::actingAs($this->usuario)
        ->test(MessageComposer::class, ['conversationId' => $conversa->id])
        ->assertViewHas('templatesDisponiveis', fn ($t) => $t->isEmpty())
        ->assertDontSee('cobrado pela Meta');
});
