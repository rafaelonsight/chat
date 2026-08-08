<?php

use App\Filament\Pages\Agenda;
use App\Models\{Appointment, AppointmentGuest, Channel, Contact, Conversation, Meeting, Message, Tenant, User};
use App\Notifications\ConviteDeCompromisso;
use App\Services\Agendamento\Convite;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Avisar os convidados de um compromisso.
 *
 * TRES CAMINHOS PARA A MESMA INFORMACAO — e-mail, WhatsApp, e o bloco que a pessoa copia — e o
 * texto e UM SO. Se cada caminho montasse o proprio, o dia em que alguem corrigisse a hora num
 * deles os outros dois continuariam errados.
 *
 * O COPIAR EXISTE PORQUE OS OUTROS DOIS FALHAM: convidado sem e-mail e sem WhatsApp, grupo de
 * familia, Telegram, o cliente que so usa Instagram. Nao da para prever por onde a pessoa fala,
 * e um bloco de texto pronto resolve todos esses casos sem depender de integracao nenhuma.
 *
 * CONVIDADO NAO E O CONTATO DO COMPROMISSO. Aquele campo responde "com quem e a reuniao" e e um
 * so; convidado sao varios, e cada um recebe pelo seu caminho, em momentos diferentes.
 */

beforeEach(function () {
    Notification::fake();
    Http::fake(['*' => Http::response([], 200)]);

    $this->travelTo(Carbon::parse('2026-08-10 09:00:00'));

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'cvt']);
    TenantContext::set($this->conta->id);

    $this->eu = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Eu',
        'email' => 'eu@cvt.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Comercial',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cvt',
    ]);

    $this->joana = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Joana',
        'telefone_e164' => '+5541944440000', 'jid' => '5541944440000@s.whatsapp.net',
        'email' => 'joana@empresa.com.br',
    ]);

    $this->pedro = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Pedro',
        'telefone_e164' => '+5541933330000', 'jid' => '5541933330000@s.whatsapp.net',
    ]);

    $this->actingAs($this->eu);
});

afterEach(fn () => TenantContext::forget());

function compromisso($ctx, array $extra = []): Appointment
{
    return Appointment::create($extra + [
        'tenant_id'   => $ctx->conta->id,
        'user_id'     => $ctx->eu->id,
        'criado_por'  => $ctx->eu->id,
        'tipo'        => Appointment::COMPROMISSO,
        'titulo'      => 'Reunião de orçamento',
        'comeca_em'   => Carbon::parse('2026-08-13 14:00:00'),
        'duracao_min' => 60,
    ]);
}

function convidar($ctx, Appointment $a, ?Contact $contato = null, ?string $email = null): AppointmentGuest
{
    return AppointmentGuest::create([
        'tenant_id'      => $ctx->conta->id,
        'appointment_id' => $a->id,
        'contact_id'     => $contato?->id,
        'nome'           => $contato?->nomeExibicao() ?? 'Convidado',
        'email'          => $email ?? $contato?->email,
    ]);
}

// -------------------------------------------------------------------- o texto

it('o bloco para copiar tem tudo que quem le precisa', function () {
    $a = compromisso($this);

    $texto = app(Convite::class)->texto($a);

    expect($texto)->toContain('Reunião de orçamento')
        ->and($texto)->toContain('quinta-feira, 13 de agosto')
        ->and($texto)->toContain('14:00')
        ->and($texto)->toContain('60 minutos')
        ->and($texto)->toContain('Eu');
});

it('o bloco carrega o link quando a reuniao e por video', function () {
    $a = compromisso($this);

    $reuniao = Meeting::abrir([
        'tenant_id' => $this->conta->id, 'appointment_id' => $a->id,
        'titulo' => $a->titulo, 'comecou_em' => $a->comeca_em,
    ]);

    $texto = app(Convite::class)->texto($a->refresh());

    expect($texto)->toContain($reuniao->url())
        // O link sozinho na linha: quase todo aplicativo so transforma em clicavel assim.
        ->and($texto)->toContain("\n".$reuniao->url());
});

it('compromisso presencial nao promete video nenhum', function () {
    expect(app(Convite::class)->texto(compromisso($this)))->not->toContain('vídeo');
});

// -------------------------------------------------------------------- e-mail

it('manda por e-mail para quem tem e-mail, e conta quem ficou de fora', function () {
    $a = compromisso($this);
    convidar($this, $a, $this->joana);
    convidar($this, $a, $this->pedro);          // sem e-mail
    convidar($this, $a, email: 'socio@x.com.br');

    $r = app(Convite::class)->porEmail($a->refresh());

    expect($r['enviados'])->toBe(2)
        ->and($r['sem_email'])->toBe(1);

    Notification::assertSentOnDemand(ConviteDeCompromisso::class);
    Notification::assertCount(2);
});

it('guarda quando o e-mail saiu, para nao mandar de novo as cegas', function () {
    $a = compromisso($this);
    $convidado = convidar($this, $a, $this->joana);

    expect($convidado->avisado())->toBeFalse();

    app(Convite::class)->porEmail($a->refresh());

    expect($convidado->refresh()->email_em)->not->toBeNull()
        ->and($convidado->comoFoiAvisado())->toBe('avisado por e-mail');
});

it('sem convidado nenhum nao manda e-mail', function () {
    app(Convite::class)->porEmail(compromisso($this));

    Notification::assertNothingSent();
});

it('a tela avisa quando ninguem tem e-mail', function () {
    $a = compromisso($this);
    convidar($this, $a, $this->pedro);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->call('enviarPorEmail')
        ->assertSee('Nenhum convidado tem e-mail');
});

// ------------------------------------------------------------------ WhatsApp

it('manda pelo canal escolhido para os contatos escolhidos', function () {
    $a = compromisso($this);

    $r = app(Convite::class)->porWhatsapp($a, $this->canal, [$this->joana->id, $this->pedro->id]);

    expect($r['enviados'])->toBe(2)
        ->and($r['fora'])->toBe([]);

    $mensagens = Message::where('direcao', 'out')->get();

    expect($mensagens)->toHaveCount(2)
        ->and($mensagens->first()->corpo)->toContain('Reunião de orçamento')
        ->and($mensagens->first()->channel_id)->toBe($this->canal->id)
        ->and($mensagens->first()->automatica)->toBeTrue();
});

it('quem foi avisado no WhatsApp entra na lista de convidados', function () {
    // Sem isso, "quem eu ja avisei?" ficaria sem resposta na proxima vez que a tela abrisse.
    $a = compromisso($this);

    app(Convite::class)->porWhatsapp($a, $this->canal, [$this->joana->id]);

    $convidado = AppointmentGuest::first();

    expect($convidado->contact_id)->toBe($this->joana->id)
        ->and($convidado->whatsapp_em)->not->toBeNull()
        ->and($convidado->comoFoiAvisado())->toBe('avisado no WhatsApp');
});

it('avisar duas vezes nao duplica o convidado', function () {
    $a = compromisso($this);

    app(Convite::class)->porWhatsapp($a, $this->canal, [$this->joana->id]);
    app(Convite::class)->porWhatsapp($a, $this->canal, [$this->joana->id]);

    expect(AppointmentGuest::count())->toBe(1)
        ->and(Message::where('direcao', 'out')->count())->toBe(2);
});

it('fora da janela de 24h devolve o nome de quem nao recebeu', function () {
    // A pessoa precisa saber quais convites ela ainda vai ter de mandar a mao.
    $this->canal->update(['tipo' => 'meta_cloud']);

    $a = compromisso($this);

    $r = app(Convite::class)->porWhatsapp($a->refresh(), $this->canal->refresh(), [$this->joana->id]);

    expect($r['enviados'])->toBe(0)
        ->and($r['fora'])->toBe(['Joana'])
        ->and(Message::count())->toBe(0);
});

it('o e-mail e o WhatsApp nao apagam um ao outro', function () {
    // Sao dois fatos: "ja avisei essa pessoa?" precisa saber por onde.
    $a = compromisso($this);
    convidar($this, $a, $this->joana);

    app(Convite::class)->porEmail($a->refresh());
    app(Convite::class)->porWhatsapp($a->refresh(), $this->canal, [$this->joana->id]);

    expect(AppointmentGuest::first()->comoFoiAvisado())->toBe('avisado por e-mail e WhatsApp');
});

// --------------------------------------------------------------- pela tela

it('convida contato do CRM e grava ao salvar', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Visita')
        ->set('buscaConvidado', 'Joana')
        ->assertViewHas('paraConvidar', fn ($c) => $c->count() === 1)
        ->call('convidarContato', $this->joana->id)
        ->assertSet('buscaConvidado', '')
        ->call('salvar');

    $convidado = AppointmentGuest::first();

    expect($convidado->contact_id)->toBe($this->joana->id)
        ->and($convidado->nome)->toBe('Joana')
        // O e-mail vem do cadastro: nao ha por que pedir de novo o que ja temos.
        ->and($convidado->email)->toBe('joana@empresa.com.br');
});

it('convida quem nao e do CRM, so pelo e-mail', function () {
    // Exigir cadastro faria a pessoa inventar contato para conseguir convidar.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Visita')
        ->set('emailNovo', 'Socio@Empresa.com.BR')
        ->call('convidarEmail')
        ->assertSet('emailNovo', '')
        ->call('salvar');

    $convidado = AppointmentGuest::first();

    expect($convidado->contact_id)->toBeNull()
        // Minusculas: senao "Joana@x" e "joana@x" viram duas pessoas e recebem dois convites.
        ->and($convidado->email)->toBe('socio@empresa.com.br')
        ->and($convidado->nome)->toBe('socio');
});

it('e-mail torto nao entra na lista', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('emailNovo', 'nao-e-email')
        ->call('convidarEmail')
        ->assertHasErrors('emailNovo')
        ->assertSet('convidados', []);
});

it('o mesmo convidado nao entra duas vezes', function () {
    $tela = Livewire::actingAs($this->eu)->test(Agenda::class)->call('novo')->set('titulo', 'X');

    $tela->call('convidarContato', $this->joana->id);
    $tela->call('convidarContato', $this->joana->id);
    $tela->set('emailNovo', 'socio@x.com')->call('convidarEmail');
    $tela->set('emailNovo', 'SOCIO@x.com')->call('convidarEmail');

    expect($tela->get('convidados'))->toHaveCount(2);
});

it('tirar da lista apaga no banco ao salvar', function () {
    $a = compromisso($this);
    convidar($this, $a, $this->joana);
    convidar($this, $a, $this->pedro);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->assertCount('convidados', 2)
        ->call('tirarConvidado', 0)
        ->call('salvar');

    expect(AppointmentGuest::count())->toBe(1);
});

it('salvar duas vezes nao duplica nem apaga quem ja foi avisado', function () {
    $a = compromisso($this);
    convidar($this, $a, $this->joana);

    app(Convite::class)->porEmail($a->refresh());

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->set('titulo', 'Reunião de orçamento (revisada)')
        ->call('salvar');

    expect(AppointmentGuest::count())->toBe(1)
        ->and(AppointmentGuest::first()->email_em)->not->toBeNull();
});

// ------------------------------------------------------- o pop-up do WhatsApp

it('a caixa de avisar ja vem com os convidados marcados', function () {
    // Eles sao a resposta certa em quase todo caso, e desmarcar da menos trabalho que procurar
    // cada um de novo.
    $a = compromisso($this);
    convidar($this, $a, $this->joana);
    convidar($this, $a, email: 'socio@x.com.br');   // sem contato: nao entra

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->call('abrirAviso')
        ->assertSet('avisando', true)
        ->assertSet('paraAvisar', [$this->joana->id])
        // Com um canal so nao ha escolha a fazer.
        ->assertSet('canalDoAviso', $this->canal->id);
});

it('com mais de um canal, nenhum vem escolhido', function () {
    // Escolher pelo primeiro mandaria do numero errado sem avisar.
    Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Suporte',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cvt2',
    ]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', compromisso($this)->id)
        ->call('abrirAviso')
        ->assertSet('canalDoAviso', null);
});

it('sem canal escolhido nao manda nada', function () {
    Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Suporte',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cvt3',
    ]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', compromisso($this)->id)
        ->call('abrirAviso')
        ->call('alternarParaAvisar', $this->joana->id)
        ->call('avisarPorWhatsapp')
        ->assertHasErrors('canalDoAviso');

    expect(Message::count())->toBe(0);
});

it('sem ninguem selecionado nao manda nada', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', compromisso($this)->id)
        ->call('abrirAviso')
        ->set('paraAvisar', [])
        ->call('avisarPorWhatsapp')
        ->assertHasErrors('paraAvisar');

    expect(Message::count())->toBe(0);
});

it('seleciona mais de um contato e manda para todos', function () {
    $a = compromisso($this);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->call('abrirAviso')
        ->call('alternarParaAvisar', $this->joana->id)
        ->call('alternarParaAvisar', $this->pedro->id)
        ->call('avisarPorWhatsapp')
        ->assertSet('avisando', false)
        ->assertSee('2 contatos');

    expect(Message::where('direcao', 'out')->count())->toBe(2)
        ->and(AppointmentGuest::count())->toBe(2);
});

it('clicar duas vezes no mesmo contato desmarca', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', compromisso($this)->id)
        ->call('abrirAviso')
        ->call('alternarParaAvisar', $this->pedro->id)
        ->call('alternarParaAvisar', $this->pedro->id)
        ->assertSet('paraAvisar', []);
});

it('a busca da caixa acha o contato pelo nome', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', compromisso($this)->id)
        ->call('abrirAviso')
        ->assertViewHas('contatosDoAviso', fn ($c) => $c->count() === 2)
        ->set('buscaParaAvisar', 'Ped')
        ->assertViewHas('contatosDoAviso', fn ($c) => $c->count() === 1 && $c->first()->nome === 'Pedro');
});

it('a caixa fechada nao consulta contato nenhum', function () {
    // Conta com dez mil contatos desenharia dez mil linhas, e a tela morre antes de aparecer.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->assertViewHas('contatosDoAviso', fn ($c) => $c->isEmpty());
});

it('nao mistura conta', function () {
    $a = compromisso($this);
    convidar($this, $a, $this->joana);

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'cvt-outra']);
    TenantContext::set($outra->id);

    expect(AppointmentGuest::count())->toBe(0);
});

// ------------------------------------------------- convidar sem salvar antes

it('convidar e mandar o e-mail sem salvar antes ainda alcança quem está na tela', function () {
    /*
     * A ARMADILHA QUE ISTO FECHA.
     *
     * A lista de convidados vive em memoria ate salvar, e os botoes de convite agiam sobre o
     * que estava no BANCO. Quem adicionava um convidado e clicava em "enviar" sem salvar antes
     * mandava para a lista velha — ou para ninguem — e a tela respondia "adicione convidados"
     * com dois nomes na frente da pessoa.
     *
     * Aconteceu de verdade no primeiro uso: tres convidados gravados e nenhum e-mail enviado.
     */
    $a = compromisso($this);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->call('convidarContato', $this->joana->id)
        // sem passar por salvar
        ->call('enviarPorEmail')
        ->assertSee('1 convidado');

    expect(AppointmentGuest::count())->toBe(1)
        ->and(AppointmentGuest::first()->email_em)->not->toBeNull();

    Notification::assertCount(1);
});

it('avisar no WhatsApp sem salvar antes tambem vale', function () {
    $a = compromisso($this);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->call('convidarContato', $this->pedro->id)
        ->call('abrirAviso')
        ->assertSet('paraAvisar', [$this->pedro->id])
        ->call('avisarPorWhatsapp');

    expect(Message::where('direcao', 'out')->count())->toBe(1)
        ->and(AppointmentGuest::count())->toBe(1);
});

it('clicar em enviar sem compromisso salvo diz o que fazer, em vez de nao fazer nada', function () {
    // Silencio era o pior desfecho: a pessoa clicava e nada acontecia na tela.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Ainda não salvo')
        ->call('convidarContato', $this->joana->id)
        ->call('enviarPorEmail')
        ->assertSee('Salve o compromisso antes');

    Notification::assertNothingSent();
});

it('tirar da tela e mandar nao alcança quem saiu', function () {
    // O botao age sobre o que a pessoa esta vendo, e nao sobre o que sobrou no banco.
    $a = compromisso($this);
    convidar($this, $a, $this->joana);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->call('tirarConvidado', 0)
        ->call('enviarPorEmail')
        ->assertSee('Adicione convidados');

    expect(AppointmentGuest::count())->toBe(0);
    Notification::assertNothingSent();
});
