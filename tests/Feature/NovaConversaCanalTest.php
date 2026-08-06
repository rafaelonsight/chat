<?php

use App\Jobs\SendTextMessage;
use App\Livewire\Inbox\NewConversation;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Http, Queue};
use Livewire\Livewire;

/*
 * Comecar conversa escolhendo o canal.
 *
 * O defeito que isto conserta: a tela pegava sempre o primeiro canal conectado por ordem de
 * id e validava o numero por um endpoint que so a Evolution tem. Na pratica dava para
 * RESPONDER pelo numero oficial, mas nunca COMECAR.
 */

beforeEach(function () {
    config(['services.meta.token' => 'EAA-env', 'services.meta.versao' => 'v23.0']);

    Queue::fake([SendTextMessage::class]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'novacanal']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Atendente',
        'email' => 'a@nc.test', 'password' => 'segredo123',
    ]);

    // Resposta da Evolution para "esse numero tem WhatsApp?", por propriedade.
    $this->numeroExiste = true;

    Http::fake(['*' => fn () => Http::response([[
        'exists' => $this->numeroExiste,
        'jid'    => '5541999998888@s.whatsapp.net',
        'number' => '5541999998888',
    ]])]);
});

afterEach(fn () => TenantContext::forget());

function canalEvolutionAberto(string $nome = 'Pessoal'): Channel
{
    $c = Channel::create(['nome' => $nome])->refresh();
    $c->forceFill(['status' => 'open', 'instance_name' => 'inst-'.$c->id])->save();

    return $c->refresh();
}

function canalOficialConfigurado(string $nome = 'Oficial'): Channel
{
    $c = Channel::create([
        'nome' => $nome, 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111222', 'meta_waba_id' => '362',
    ])->refresh();

    // status DIFERENTE de open de proposito: canal oficial nao "conecta", e o filtro antigo
    // por status era exatamente o que o escondia.
    $c->forceFill(['status' => 'close'])->save();

    return $c->refresh();
}

function painel()
{
    return Livewire::actingAs(test()->usuario)->test(NewConversation::class)->call('alternar');
}

// ================================================== o canal oficial fica visivel

it('canal oficial aparece como opcao mesmo sem status conectado', function () {
    // Era o defeito: filtrar por status = open escondia o oficial para sempre.
    canalEvolutionAberto();
    canalOficialConfigurado();

    painel()->assertSee('Oficial')->assertSee('Pessoal');
});

it('com um canal so, nao pergunta nada e usa aquele', function () {
    $oficial = canalOficialConfigurado();

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    painel()->call('selecionarContato', $contato->id)->assertHasNoErrors();

    expect(Conversation::first()->channel_id)->toBe($oficial->id);
});

// =========================================== com mais de um, ele PRECISA escolher

it('com dois canais, exige escolher em vez de adivinhar', function () {
    // Escolher pelo menor id sairia do numero errado sem avisar. No canal oficial isso custa
    // dinheiro e sai com a identidade errada para o cliente final.
    canalEvolutionAberto();
    canalOficialConfigurado();

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    painel()->call('selecionarContato', $contato->id)->assertHasErrors(['canalId']);

    expect(Conversation::count())->toBe(0);
});

it('escolhendo o oficial, a conversa nasce no oficial', function () {
    canalEvolutionAberto();
    $oficial = canalOficialConfigurado();

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    painel()
        ->set('canalId', (string) $oficial->id)
        ->call('selecionarContato', $contato->id)
        ->assertHasNoErrors();

    expect(Conversation::first()->channel_id)->toBe($oficial->id);
});

it('canal de outro tenant nao entra na lista nem por id', function () {
    canalEvolutionAberto();

    $intruso = Channel::withoutGlobalScope('tenant')->create([
        'tenant_id' => Tenant::create(['nome' => 'Outro', 'slug' => 'outro'])->id,
        'nome'      => 'Do vizinho',
        'status'    => 'open',
    ]);

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    painel()
        ->set('canalId', (string) $intruso->id)
        ->call('selecionarContato', $contato->id)
        ->assertHasErrors(['canalId']);

    expect(Conversation::count())->toBe(0);
});

// ====================================== verificar numero: quem sabe e o provedor

it('numero sem WhatsApp e barrado no canal da Evolution', function () {
    canalEvolutionAberto();
    $this->numeroExiste = false;

    painel()->set('termo', '41 99999-8888')->call('iniciar')->assertHasErrors(['termo']);

    expect(Contact::count())->toBe(0);
});

it('no canal oficial segue adiante, porque a API nao tem essa consulta', function () {
    // "Nao sei" nao e "nao tem". Negar por uma pergunta que ninguem respondeu impediria
    // QUALQUER conversa nova pelo numero oficial, ja que ali toda pergunta fica sem resposta.
    canalOficialConfigurado();

    painel()->set('termo', '41 99999-8888')->call('iniciar')->assertHasNoErrors();

    expect(Contact::count())->toBe(1)
        ->and(Conversation::count())->toBe(1);

    // E nao houve chamada nenhuma para checar numero.
    Http::assertNothingSent();
});

// ============================================= a janela de 24h ensina a regra

it('primeira mensagem no oficial sem janela aberta e barrada com instrucao', function () {
    // Abrir a conversa e deixar a mensagem falhar daria bolha vermelha de saida, e o
    // atendente sem saber que o caminho era template.
    canalOficialConfigurado();

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    painel()
        ->set('primeiraMensagem', 'Ola, tudo bem?')
        ->call('selecionarContato', $contato->id)
        ->assertHasErrors(['primeiraMensagem']);

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

it('sem primeira mensagem, a conversa abre no oficial para escolher template', function () {
    canalOficialConfigurado();

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    painel()->call('selecionarContato', $contato->id)->assertHasNoErrors();

    expect(Conversation::count())->toBe(1)
        ->and(Message::count())->toBe(0);
});

it('com a janela aberta, a primeira mensagem sai normalmente no oficial', function () {
    $oficial = canalOficialConfigurado();

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    // Cliente escreveu antes: janela aberta.
    Conversation::abertaOuNova($oficial->id, $contato->id)
        ->forceFill(['ultima_entrada_em' => now()])->save();

    painel()
        ->set('primeiraMensagem', 'Ola, tudo bem?')
        ->call('selecionarContato', $contato->id)
        ->assertHasNoErrors();

    expect(Message::count())->toBe(1);

    Queue::assertPushed(SendTextMessage::class);
});

it('na Evolution a primeira mensagem nao tem essa restricao', function () {
    // A regra e do canal, nao do sistema: avisar de um limite que nao vale ali ensinaria o
    // atendente a ignorar o aviso.
    canalEvolutionAberto();

    $contato = Contact::create(['nome' => 'Cliente', 'telefone_e164' => '+5541999998888']);

    painel()
        ->set('primeiraMensagem', 'Ola')
        ->call('selecionarContato', $contato->id)
        ->assertHasNoErrors();

    expect(Message::count())->toBe(1);
});
