<?php

use App\Filament\Pages\Agenda;
use App\Livewire\Crm\LinksDeAgendamento;
use App\Livewire\Publico\Reservar;
use App\Models\{Appointment, BookingPage, Channel, Contact, Conversation, Message, Tenant, User};
use App\Services\Agendamento\Reserva;
use App\Services\Agendamento\VagaTomada;
use App\Services\Agendamento\Vagas;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Pagina de reserva: o cliente escolhe o horario sozinho.
 *
 * A REGRA DE OURO E NAO OFERECER O QUE NAO EXISTE. Uma vaga mostrada e um compromisso que o
 * cliente considera fechado; se ela sumir na confirmacao, o estrago e maior do que se ela
 * nunca tivesse aparecido. Por isso todo corte aqui e para menos — fora da faixa, cedo demais,
 * encostado em outro compromisso, dia cheio, nao aparece.
 *
 * E A CONTA VEM DO SLUG. A URL nao carrega tenant nenhum: e a unica tela do sistema onde o
 * isolamento nao pode vir da sessao, porque nao ha sessao.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'reserva']);
    TenantContext::set($this->conta->id);

    $this->dono = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Tecnico',
        'email' => 'tecnico@reserva.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    // Segunda-feira, para os testes de faixa nao dependerem do dia em que rodam.
    $this->travelTo(Carbon::parse('2026-08-10 08:00:00'));

    $this->pagina = BookingPage::create([
        'tenant_id'          => $this->conta->id,
        'user_id'            => $this->dono->id,
        'slug'               => 'visita-tecnica',
        'titulo'             => 'Visita técnica',
        'duracao_min'        => 30,
        'antecedencia_horas' => 0,
        'janela_dias'        => 30,
        'disponibilidade'    => [
            ['dia' => 1, 'de' => '09:00', 'ate' => '12:00'],
            ['dia' => 1, 'de' => '13:00', 'ate' => '15:00'],
        ],
    ]);

    $this->actingAs($this->dono);
});

afterEach(fn () => TenantContext::forget());

function vagasDe($pagina, string $dia): array
{
    return (new Vagas($pagina))->doDia(Carbon::parse($dia));
}

function horasDe(array $vagas): array
{
    return array_map(fn (Carbon $v) => $v->format('H:i'), $vagas);
}

function compromissoEm($ctx, string $quando, int $duracao = 30, array $extra = []): Appointment
{
    return Appointment::create($extra + [
        'tenant_id'   => $ctx->conta->id,
        'user_id'     => $ctx->dono->id,
        'tipo'        => Appointment::COMPROMISSO,
        'titulo'      => 'Ja marcado',
        'comeca_em'   => $quando,
        'duracao_min' => $duracao,
    ]);
}

// ------------------------------------------------------------------ as vagas

it('corta a faixa em vagas do tamanho da reuniao', function () {
    // 9h as 12h em pedacos de 30min sao seis, e a ultima comeca 11:30 — nao 12:00, que
    // terminaria fora da faixa.
    $manha = array_filter(horasDe(vagasDe($this->pagina, '2026-08-10')), fn ($h) => $h < '12:00');

    expect(array_values($manha))->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30']);
});

it('respeita os dois turnos, sem oferecer o almoco', function () {
    expect(horasDe(vagasDe($this->pagina, '2026-08-10')))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30']);
});

it('dia sem faixa nao tem vaga nenhuma', function () {
    // Terca nao foi marcada na disponibilidade.
    expect(vagasDe($this->pagina, '2026-08-11'))->toBe([]);
});

it('nao oferece horario ja ocupado', function () {
    compromissoEm($this, '2026-08-10 10:00:00', 60);

    expect(horasDe(vagasDe($this->pagina, '2026-08-10')))
        ->not->toContain('10:00')
        ->not->toContain('10:30')
        ->toContain('11:00');
});

it('a folga tira tambem o horario colado no que ja existe', function () {
    // Quem sai de uma visita as 10h com 15 minutos de folga nao pode ter a proxima as 10h31.
    $this->pagina->update(['intervalo_min' => 15]);
    compromissoEm($this, '2026-08-10 10:00:00', 30);

    expect(horasDe(vagasDe($this->pagina->refresh(), '2026-08-10')))
        ->not->toContain('10:00')
        ->not->toContain('10:30')
        ->not->toContain('09:30');
});

it('lembrete nao ocupa horario', function () {
    // Bilhete para si mesmo nao pode fechar uma vaga de visita sem ninguem perceber.
    compromissoEm($this, '2026-08-10 10:00:00', 30, [
        'tipo' => Appointment::LEMBRETE, 'duracao_min' => null,
    ]);

    expect(horasDe(vagasDe($this->pagina, '2026-08-10')))->toContain('10:00');
});

it('compromisso de outra pessoa nao tapa a minha agenda', function () {
    $outro = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Outro',
        'email' => 'outro@reserva.test', 'password' => 'segredo123',
    ]);

    compromissoEm($this, '2026-08-10 10:00:00', 60, ['user_id' => $outro->id]);

    expect(horasDe(vagasDe($this->pagina, '2026-08-10')))->toContain('10:00');
});

it('a antecedencia minima come o comeco do dia', function () {
    // Sem ela o cliente marca para daqui a dez minutos e a pessoa descobre quando ja passou.
    $this->travelTo(Carbon::parse('2026-08-10 09:10:00'));
    $this->pagina->update(['antecedencia_horas' => 2]);

    expect(horasDe(vagasDe($this->pagina->refresh(), '2026-08-10')))
        ->not->toContain('10:30')
        ->toContain('11:30');
});

it('nao oferece nada depois da janela de dias', function () {
    $this->pagina->update(['janela_dias' => 3]);

    $dias = array_keys((new Vagas($this->pagina->refresh()))->porDia());

    expect($dias)->toBe(['2026-08-10']);
});

it('nao oferece o passado', function () {
    $this->travelTo(Carbon::parse('2026-08-10 13:10:00'));

    expect(horasDe(vagasDe($this->pagina, '2026-08-10')))->toBe(['13:30', '14:00', '14:30']);
});

it('pagina fechada nao oferece nada', function () {
    $this->pagina->update(['ativa' => false]);

    expect((new Vagas($this->pagina->refresh()))->porDia())->toBe([]);
});

it('o teto do dia fecha o dia quando enche', function () {
    $this->pagina->update(['limite_dia' => 2]);

    compromissoEm($this, '2026-08-10 09:00:00', 30, ['booking_page_id' => $this->pagina->id]);
    expect(vagasDe($this->pagina->refresh(), '2026-08-10'))->not->toBe([]);

    compromissoEm($this, '2026-08-10 11:00:00', 30, ['booking_page_id' => $this->pagina->id]);
    expect(vagasDe($this->pagina->refresh(), '2026-08-10'))->toBe([]);
});

it('o teto conta so o que veio pelo link', function () {
    // Compromisso posto a mao pela equipe nao gasta a cota do link.
    $this->pagina->update(['limite_dia' => 1]);
    compromissoEm($this, '2026-08-10 09:00:00', 30);

    expect(vagasDe($this->pagina->refresh(), '2026-08-10'))->not->toBe([]);
});

// -------------------------------------------------------------------- marcar

it('marca, cria o contato e prende o compromisso na pagina', function () {
    $marcado = app(Reserva::class)->marcar(
        $this->pagina, Carbon::parse('2026-08-10 10:00:00'), 'Joana da Silva', '41999887766', 'Portão azul',
    );

    $contato = Contact::first();

    expect($contato->nome)->toBe('Joana da Silva')
        ->and($contato->telefone_e164)->toBe('+5541999887766')
        ->and($marcado->contact_id)->toBe($contato->id)
        ->and($marcado->user_id)->toBe($this->dono->id)
        ->and($marcado->booking_page_id)->toBe($this->pagina->id)
        ->and($marcado->duracao_min)->toBe(30)
        ->and($marcado->descricao)->toBe('Portão azul')
        ->and($marcado->titulo)->toContain('Joana da Silva');
});

it('reaproveita o contato que ja existia, sem renomear', function () {
    // O cadastro do CRM vale mais que o que o visitante digitou na pressa.
    $antes = Contact::acharOuCriarPorTelefone('+5541999887766', ['nome' => 'Joana - Padaria']);

    app(Reserva::class)->marcar($this->pagina, Carbon::parse('2026-08-10 10:00:00'), 'jo', '41999887766');

    expect(Contact::count())->toBe(1)
        ->and($antes->refresh()->nome)->toBe('Joana - Padaria');
});

it('a vaga some para o proximo depois de reservada', function () {
    app(Reserva::class)->marcar($this->pagina, Carbon::parse('2026-08-10 10:00:00'), 'Joana', '41999887766');

    expect(horasDe(vagasDe($this->pagina, '2026-08-10')))->not->toContain('10:00');
});

it('recusa horario que nao esta na lista de vagas', function () {
    // Entre ver a vaga e confirmar passam minutos, e a agenda nao para nesse meio tempo.
    app(Reserva::class)->marcar($this->pagina, Carbon::parse('2026-08-10 10:00:00'), 'Joana', '41999887766');

    expect(fn () => app(Reserva::class)->marcar(
        $this->pagina, Carbon::parse('2026-08-10 10:00:00'), 'Pedro', '41988776655',
    ))->toThrow(VagaTomada::class);

    expect(Appointment::count())->toBe(1);
});

it('recusa horario fora de qualquer faixa', function () {
    expect(fn () => app(Reserva::class)->marcar(
        $this->pagina, Carbon::parse('2026-08-10 12:30:00'), 'Pedro', '41988776655',
    ))->toThrow(VagaTomada::class);
});

it('o banco tambem barra a corrida de dois no mesmo segundo', function () {
    // A conferencia em PHP diz sim para os dois quando nenhum estava gravado ainda; so o
    // indice unico decide quem venceu.
    compromissoEm($this, '2026-08-10 10:00:00', 30, ['booking_page_id' => $this->pagina->id]);

    expect(fn () => compromissoEm($this, '2026-08-10 10:00:00', 30, ['booking_page_id' => $this->pagina->id]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('marcar a mao no mesmo horario continua permitido', function () {
    // As vezes e proposital, e o indice unico e so para o que veio do link.
    compromissoEm($this, '2026-08-10 10:00:00');
    compromissoEm($this, '2026-08-10 10:00:00');

    expect(Appointment::count())->toBe(2);
});

it('telefone impossivel nao vira reserva', function () {
    expect(fn () => app(Reserva::class)->marcar($this->pagina, Carbon::parse('2026-08-10 10:00:00'), 'X', '123'))
        ->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------------------ o aviso no WhatsApp

it('sem canal escolhido nao manda mensagem nenhuma', function () {
    // Mandar mensagem para numero que nunca falou com a gente e o gesto que derruba um canal
    // por QR, e quem paga e o atendimento inteiro do cliente.
    app(Reserva::class)->marcar($this->pagina, Carbon::parse('2026-08-10 10:00:00'), 'Joana', '41999887766');

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

it('com canal escolhido, confirma no WhatsApp', function () {
    $canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Comercial',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'res',
    ]);

    $this->pagina->update(['channel_id' => $canal->id]);

    app(Reserva::class)->marcar($this->pagina->refresh(), Carbon::parse('2026-08-10 10:00:00'), 'Joana', '41999887766');

    $mensagem = Message::first();

    expect($mensagem)->not->toBeNull()
        ->and($mensagem->direcao)->toBe('out')
        ->and($mensagem->automatica)->toBeTrue()
        ->and($mensagem->corpo)->toContain('10:00')
        ->and($mensagem->corpo)->toContain('10/08/2026');
});

// -------------------------------------------------------------- a tela publica

it('a tela publica abre sem login e mostra os horarios', function () {
    auth()->logout();
    TenantContext::forget();

    $this->withoutExceptionHandling();
    $this->get('/agendar/visita-tecnica')
        ->assertSuccessful()
        ->assertSee('Visita técnica')
        ->assertSee('Tecnico')
        ->assertSee('09:00');
});

it('slug que nao existe da pagina nao encontrada', function () {
    TenantContext::forget();

    $this->get('/agendar/nao-existe')->assertNotFound();
});

it('o visitante escolhe e confirma', function () {
    TenantContext::forget();

    Livewire::test(Reservar::class, ['slug' => 'visita-tecnica'])
        ->call('escolherDia', '2026-08-10')
        ->call('escolherHora', '2026-08-10 10:00:00')
        ->set('nome', 'Joana')
        ->set('telefone', '41999887766')
        ->call('confirmar')
        ->assertSet('confirmado', '2026-08-10 10:00:00');

    $marcado = Appointment::withoutGlobalScope('tenant')->first();

    expect($marcado->comeca_em->format('H:i'))->toBe('10:00')
        ->and($marcado->tenant_id)->toBe($this->conta->id);
});

it('sem nome e sem telefone nao confirma', function () {
    TenantContext::forget();

    Livewire::test(Reservar::class, ['slug' => 'visita-tecnica'])
        ->call('escolherHora', '2026-08-10 10:00:00')
        ->call('confirmar')
        ->assertHasErrors(['nome', 'telefone']);

    expect(Appointment::withoutGlobalScope('tenant')->count())->toBe(0);
});

it('telefone torto e recusado com recado, e nao com erro feio', function () {
    TenantContext::forget();

    Livewire::test(Reservar::class, ['slug' => 'visita-tecnica'])
        ->call('escolherHora', '2026-08-10 10:00:00')
        ->set('nome', 'Joana')
        ->set('telefone', '99')
        ->call('confirmar')
        ->assertHasErrors('telefone');
});

it('quando a vaga cai entre ver e confirmar, o visitante e avisado', function () {
    TenantContext::forget();

    $tela = Livewire::test(Reservar::class, ['slug' => 'visita-tecnica'])
        ->call('escolherHora', '2026-08-10 10:00:00')
        ->set('nome', 'Pedro')
        ->set('telefone', '41988776655');

    // alguem chegou na frente
    TenantContext::set($this->conta->id);
    compromissoEm($this, '2026-08-10 10:00:00', 30, ['booking_page_id' => $this->pagina->id]);
    TenantContext::forget();

    $tela->call('confirmar')
        ->assertSet('confirmado', null)
        ->assertSet('quando', '')
        ->assertSee('acabou de ser reservado');
});

it('a tela publica nao deixa a conta vazar para a proxima', function () {
    // A conta sai do slug, e nao da sessao: e a unica tela do sistema onde nao ha sessao de
    // onde tirar isso.
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'reserva-outra']);
    TenantContext::set($outra->id);

    $delaDono = User::create([
        'tenant_id' => $outra->id, 'name' => 'Dela',
        'email' => 'dela@reserva.test', 'password' => 'segredo123',
    ]);

    BookingPage::create([
        'tenant_id' => $outra->id, 'user_id' => $delaDono->id, 'slug' => 'consulta',
        'titulo' => 'Consulta', 'duracao_min' => 30, 'antecedencia_horas' => 0,
        'disponibilidade' => [['dia' => 1, 'de' => '09:00', 'ate' => '10:00']],
    ]);

    TenantContext::forget();

    Livewire::test(Reservar::class, ['slug' => 'consulta'])
        ->call('escolherHora', '2026-08-10 09:00:00')
        ->set('nome', 'Alguem')
        ->set('telefone', '41977665544')
        ->call('confirmar');

    $marcado = Appointment::withoutGlobalScope('tenant')->first();

    expect($marcado->tenant_id)->toBe($outra->id)
        ->and($marcado->user_id)->toBe($delaDono->id);
});

it('link fechado avisa em vez de sumir', function () {
    $this->pagina->update(['ativa' => false]);
    TenantContext::forget();

    $this->get('/agendar/visita-tecnica')
        ->assertSuccessful()
        ->assertSee('fechada no momento');
});

// ------------------------------------------------------------- o painel

it('mora dentro da Agenda, e nao num item de menu proprio', function () {
    // Configurar quando se aceita visita e olhar a semana sao a mesma cabeca no mesmo minuto.
    expect(Agenda::VISOES)->toHaveKey('link')
        ->and(class_exists('App\\Filament\\Pages\\Reservas'))->toBeFalse();

    $this->withoutExceptionHandling();
    $this->withSession([
        'login_web_'.sha1('Illuminate\Auth\SessionGuard') => $this->dono->id,
        'agenda.visao' => 'link',
    ])->get('/admin/agenda')
        ->assertSuccessful()
        ->assertSee('Visita técnica');
});

it('cria o link com horario comercial e slug legivel', function () {
    Livewire::actingAs($this->dono)->test(LinksDeAgendamento::class)
        ->call('novo')
        ->set('titulo', 'Reunião de orçamento')
        ->call('salvar')
        ->assertSet('formAberto', false);

    $nova = BookingPage::where('titulo', 'Reunião de orçamento')->first();

    expect($nova->slug)->toBe('reuniao-de-orcamento')
        ->and($nova->user_id)->toBe($this->dono->id)
        ->and($nova->disponibilidade)->toHaveCount(10)
        ->and($nova->url())->toContain('/agendar/reuniao-de-orcamento');
});

it('dois links com o mesmo nome nao brigam pelo endereco', function () {
    // O slug e unico no banco inteiro: a URL nao tem tenant dentro dela.
    Livewire::actingAs($this->dono)->test(LinksDeAgendamento::class)
        ->call('novo')->set('titulo', 'Visita técnica')->call('salvar');

    expect(BookingPage::where('slug', 'visita-tecnica-2')->exists())->toBeTrue();
});

it('link sem nenhum dia marcado nao e salvo', function () {
    // Link que nao oferece nada e pior que link nenhum: o cliente abre e acha que quebrou.
    $tela = Livewire::actingAs($this->dono)->test(LinksDeAgendamento::class)->call('novo')->set('titulo', 'Vazio');

    foreach (range(0, 6) as $dia) {
        $tela->set("horarios.$dia.ativo", false);
    }

    $tela->call('salvar')->assertHasErrors('horarios');

    expect(BookingPage::where('titulo', 'Vazio')->exists())->toBeFalse();
});

it('editar nao troca o endereco sozinho', function () {
    // Trocar o slug sozinho quebraria o link que ja esta na assinatura de e-mail de alguem.
    Livewire::actingAs($this->dono)->test(LinksDeAgendamento::class)
        ->call('editar', $this->pagina->id)
        ->set('titulo', 'Visita técnica agendada')
        ->call('salvar');

    expect($this->pagina->refresh()->slug)->toBe('visita-tecnica')
        ->and($this->pagina->titulo)->toBe('Visita técnica agendada');
});

it('fecha e reabre o link', function () {
    Livewire::actingAs($this->dono)->test(LinksDeAgendamento::class)->call('alternarAtiva', $this->pagina->id);
    expect($this->pagina->refresh()->ativa)->toBeFalse();

    Livewire::actingAs($this->dono)->test(LinksDeAgendamento::class)->call('alternarAtiva', $this->pagina->id);
    expect($this->pagina->refresh()->ativa)->toBeTrue();
});

it('nao mexe no link de outra conta', function () {
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'reserva-alheia']);
    $delaDono = User::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'name' => 'Dela',
        'email' => 'dela2@reserva.test', 'password' => 'segredo123',
    ]);

    $alheia = BookingPage::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'user_id' => $delaDono->id, 'slug' => 'alheia',
        'titulo' => 'Alheia', 'duracao_min' => 30,
        'disponibilidade' => [['dia' => 1, 'de' => '09:00', 'ate' => '10:00']],
    ]);

    Livewire::actingAs($this->dono)->test(LinksDeAgendamento::class)->call('excluir', $alheia->id);

    expect(BookingPage::withoutGlobalScope('tenant')->whereKey($alheia->id)->exists())->toBeTrue();
});
