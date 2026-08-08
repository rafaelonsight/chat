<?php

use App\Filament\Pages\Agenda;
use App\Livewire\Crm\LinksDeAgendamento;
use App\Livewire\Publico\Reservar;
use App\Models\{Appointment, BookingPage, Channel, Contact, Conversation, Meeting, Message, Tenant, User};
use App\Services\Agendamento\Reserva;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Marcar um compromisso POR VIDEO.
 *
 * TRES COISAS TEM DE ACONTECER JUNTAS, e a que mais quebra e a terceira:
 *
 *   1. abrir a sala,
 *   2. mandar o link para quem foi convidado,
 *   3. a sala VALER NO HORARIO MARCADO.
 *
 * O link de uma reuniao vence doze horas depois de a sala passar a valer. Se a sala nascesse
 * valendo AGORA, marcar uma visita para semana que vem criaria um link que morre hoje a noite —
 * e o cliente descobriria isso na quinta, na hora de entrar. Por isso a sala de uma reuniao de
 * quinta comeca na quinta, e remarcar arrasta a sala junto.
 *
 * E O HORARIO FICA TRAVADO onde importa: o link publico de agendamento nao oferece vaga que ja
 * tem compromisso em cima. Aqui dentro, marcar duas coisas na mesma hora avisa e nao impede —
 * as vezes e proposital, e recusar obrigaria a pessoa a mentir o horario para conseguir anotar.
 */

beforeEach(function () {
    config()->set('services.livekit', [
        'url'    => 'wss://video.teste',
        'key'    => 'chave-de-teste',
        'secret' => 'segredo-de-teste-com-tamanho-suficiente',
    ]);

    Http::fake(['*' => Http::response([], 200)]);

    $this->travelTo(Carbon::parse('2026-08-10 09:00:00'));

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'cpv']);
    TenantContext::set($this->conta->id);

    $this->eu = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Eu',
        'email' => 'eu@cpv.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cpv',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541955550000', 'jid' => '5541955550000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'ultima_msg_em' => now(), 'ultima_entrada_em' => now(),
    ]);

    $this->actingAs($this->eu);
});

afterEach(fn () => TenantContext::forget());

/** Marca pela tela da Agenda, com a caixinha de video ligada. */
function marcarPorVideo($ctx, string $quando = '2026-08-13T14:00', bool $comContato = true)
{
    $tela = Livewire::actingAs($ctx->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Reunião de orçamento')
        ->set('quando', $quando)
        ->set('duracao_min', 60)
        ->set('por_video', true);

    if ($comContato) {
        $tela->call('escolherContato', $ctx->contato->id);
    }

    return $tela->call('salvar');
}

// ------------------------------------------------------------ a sala e a hora

it('marcar por video abre a sala presa ao compromisso', function () {
    marcarPorVideo($this);

    $compromisso = Appointment::first();
    $reuniao = Meeting::first();

    expect($reuniao)->not->toBeNull()
        ->and($reuniao->appointment_id)->toBe($compromisso->id)
        ->and($reuniao->contact_id)->toBe($this->contato->id)
        ->and($reuniao->titulo)->toBe('Reunião de orçamento')
        ->and($compromisso->ehPorVideo())->toBeTrue();
});

it('a sala vale NO HORARIO MARCADO, e nao agora', function () {
    // Sem isto, a reuniao de quinta ganharia um link que vence hoje a noite.
    marcarPorVideo($this, '2026-08-13T14:00');

    $reuniao = Meeting::first();

    expect($reuniao->comecou_em->format('Y-m-d H:i'))->toBe('2026-08-13 14:00')
        ->and($reuniao->agendada())->toBeTrue()
        // ja da para entrar antes: quem chega dez minutos mais cedo e o normal
        ->and($reuniao->podeEntrar())->toBeTrue();
});

it('o link de uma reuniao la na frente nao vence hoje', function () {
    marcarPorVideo($this, '2026-08-13T14:00');

    $reuniao = Meeting::first();

    // amanha de noite, a reuniao de quinta continua de pe
    $this->travelTo(Carbon::parse('2026-08-11 23:00:00'));

    expect($reuniao->refresh()->expirada())->toBeFalse();

    // e depois de passar a hora dela, com a folga de doze horas, ai sim
    $this->travelTo(Carbon::parse('2026-08-14 03:00:00'));

    expect($reuniao->refresh()->expirada())->toBeTrue();
});

it('remarcar arrasta a sala junto', function () {
    // Sem isto, arrastar a visita de terca para quinta deixaria o link vencendo na terca.
    marcarPorVideo($this, '2026-08-13T14:00');

    $compromisso = Appointment::first();

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('mover', $compromisso->id, '2026-08-20', 16 * 60);

    expect($compromisso->refresh()->comeca_em->format('Y-m-d H:i'))->toBe('2026-08-20 16:00')
        ->and(Meeting::first()->comecou_em->format('Y-m-d H:i'))->toBe('2026-08-20 16:00');
});

it('editar o horario pelo formulario tambem leva a sala', function () {
    marcarPorVideo($this, '2026-08-13T14:00');

    $compromisso = Appointment::first();

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $compromisso->id)
        ->set('quando', '2026-08-13T17:30')
        ->call('salvar');

    expect(Meeting::first()->comecou_em->format('H:i'))->toBe('17:30');
});

// -------------------------------------------------------------- o convite

it('o convite sai no WhatsApp com a data e o link', function () {
    marcarPorVideo($this);

    $mensagem = Message::where('direcao', 'out')->latest('id')->first();
    $reuniao = Meeting::first();

    expect($mensagem->corpo)->toContain($reuniao->token_convidado)
        ->and($mensagem->corpo)->toContain('13/08')
        ->and($mensagem->corpo)->toContain('14:00')
        // Nao e o "vamos falar agora": quem le isso para uma reuniao de quinta toca agora,
        // entra numa sala vazia e conclui que nao funciona.
        ->and($mensagem->corpo)->not->toContain('Vamos falar por vídeo?');
});

it('o convite sai UMA vez, e nao a cada vez que se salva', function () {
    // Quem recebe dois convites da mesma reuniao fica sem saber qual vale.
    marcarPorVideo($this);

    $compromisso = Appointment::first();

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $compromisso->id)
        ->set('titulo', 'Reunião de orçamento (revisada)')
        ->call('salvar');

    expect(Message::where('direcao', 'out')->count())->toBe(1)
        ->and(Meeting::count())->toBe(1);
});

it('sem contato a sala nasce e o link fica na tela', function () {
    marcarPorVideo($this, comContato: false);

    expect(Meeting::count())->toBe(1)
        ->and(Message::count())->toBe(0);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', Appointment::first()->id)
        ->assertSet('por_video', true);
});

it('lembrete nao ganha sala nem convite', function () {
    // Bilhete para si mesmo. Abrir sala para "ligar para o contador" ainda mandaria link
    // para o contador.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Ligar para o contador')
        ->set('tipo', Appointment::LEMBRETE)
        ->set('por_video', true)
        ->call('escolherContato', $this->contato->id)
        ->call('salvar');

    expect(Meeting::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

// ------------------------------------------------------- desmarcar e apagar

it('desmarcar a caixinha encerra a sala', function () {
    marcarPorVideo($this);

    $compromisso = Appointment::first();

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $compromisso->id)
        ->set('por_video', false)
        ->call('salvar');

    expect(Meeting::withoutGlobalScope('tenant')->first()->aberta())->toBeFalse()
        ->and($compromisso->refresh()->ehPorVideo())->toBeFalse();
});

it('apagar o compromisso fecha o link', function () {
    // Link de reuniao desmarcada que continua abrindo e gente entrando numa sala que ninguem
    // mais vai atender.
    marcarPorVideo($this);

    $compromisso = Appointment::first();

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('excluir', $compromisso->id);

    $reuniao = Meeting::withoutGlobalScope('tenant')->first();

    expect($reuniao->aberta())->toBeFalse()
        ->and($reuniao->podeEntrar())->toBeFalse()
        ->and(Appointment::count())->toBe(0);
});

it('sem credencial de video o compromisso e marcado do mesmo jeito', function () {
    // Chamada de video e recurso a mais: nao pode impedir ninguem de marcar uma visita.
    config()->set('services.livekit', ['url' => null, 'key' => null, 'secret' => null]);

    marcarPorVideo($this);

    expect(Appointment::count())->toBe(1)
        ->and(Meeting::count())->toBe(0);
});

// ------------------------------------------------- o horario travado de fora

it('compromisso marcado tira a vaga do link publico', function () {
    // E isto que "travar o horario" quer dizer: ninguem de fora marca em cima.
    $pagina = BookingPage::create([
        'tenant_id' => $this->conta->id, 'user_id' => $this->eu->id,
        'slug' => 'consulta', 'titulo' => 'Consulta', 'duracao_min' => 60,
        'antecedencia_horas' => 0,
        'disponibilidade' => [['dia' => 4, 'de' => '09:00', 'ate' => '18:00']],
    ]);

    $vagas = fn () => array_map(
        fn (Carbon $v) => $v->format('H:i'),
        (new App\Services\Agendamento\Vagas($pagina))->doDia(Carbon::parse('2026-08-13')),
    );

    expect($vagas())->toContain('14:00');

    marcarPorVideo($this, '2026-08-13T14:00');

    expect($vagas())->not->toContain('14:00')->toContain('15:00');
});

it('marcar duas coisas na mesma hora avisa, e deixa salvar', function () {
    // As vezes e proposital, e recusar obrigaria a pessoa a mentir o horario para anotar.
    marcarPorVideo($this, '2026-08-13T14:00');

    $tela = Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Outra coisa')
        ->set('quando', '2026-08-13T14:30')
        ->set('duracao_min', 60);

    expect($tela->instance()->conflitos())->toHaveCount(1);

    $tela->call('salvar');

    expect(Appointment::count())->toBe(2);
});

it('horario livre nao acusa conflito', function () {
    marcarPorVideo($this, '2026-08-13T14:00');

    $tela = Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('quando', '2026-08-13T16:00')
        ->set('duracao_min', 60);

    expect($tela->instance()->conflitos())->toHaveCount(0);
});

it('lembrete nao entra no conflito', function () {
    // Ele nao ocupa horario: bilhete para si mesmo nao fecha vaga de visita.
    marcarPorVideo($this, '2026-08-13T14:00');

    $tela = Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('tipo', Appointment::LEMBRETE)
        ->set('quando', '2026-08-13T14:00');

    expect($tela->instance()->conflitos())->toHaveCount(0);
});

// ------------------------------------------------- pelo link de agendamento

it('link marcado como por video abre sala em cada reserva', function () {
    $pagina = BookingPage::create([
        'tenant_id' => $this->conta->id, 'user_id' => $this->eu->id,
        'channel_id' => $this->canal->id,
        'slug' => 'consulta-online', 'titulo' => 'Consulta online', 'duracao_min' => 30,
        'antecedencia_horas' => 0, 'por_video' => true,
        'disponibilidade' => [['dia' => 4, 'de' => '09:00', 'ate' => '12:00']],
    ]);

    $marcado = app(Reserva::class)->marcar(
        $pagina, Carbon::parse('2026-08-13 10:00:00'), 'Joana', '41999887766',
    );

    $reuniao = Meeting::first();

    expect($reuniao)->not->toBeNull()
        ->and($reuniao->appointment_id)->toBe($marcado->id)
        ->and($reuniao->comecou_em->format('Y-m-d H:i'))->toBe('2026-08-13 10:00');

    // O link entra na MESMA mensagem da confirmacao: duas mensagens seguidas viram duas
    // conversas na cabeca de quem le, e a segunda e a que se perde.
    $mensagem = Message::where('direcao', 'out')->latest('id')->first();

    expect($mensagem->corpo)->toContain($reuniao->token_convidado)
        ->and($mensagem->corpo)->toContain('10:00');
});

it('link comum nao abre sala nenhuma', function () {
    $pagina = BookingPage::create([
        'tenant_id' => $this->conta->id, 'user_id' => $this->eu->id,
        'slug' => 'visita', 'titulo' => 'Visita', 'duracao_min' => 30,
        'antecedencia_horas' => 0,
        'disponibilidade' => [['dia' => 4, 'de' => '09:00', 'ate' => '12:00']],
    ]);

    app(Reserva::class)->marcar($pagina, Carbon::parse('2026-08-13 10:00:00'), 'Joana', '41999887766');

    expect(Meeting::count())->toBe(0);
});

it('a tela publica mostra o link depois de confirmar', function () {
    // A pagina pode nao ter canal de WhatsApp: ai a tela e o unico lugar onde o link existe.
    BookingPage::create([
        'tenant_id' => $this->conta->id, 'user_id' => $this->eu->id,
        'slug' => 'online', 'titulo' => 'Online', 'duracao_min' => 30,
        'antecedencia_horas' => 0, 'por_video' => true,
        'disponibilidade' => [['dia' => 4, 'de' => '09:00', 'ate' => '12:00']],
    ]);

    TenantContext::forget();

    Livewire::test(Reservar::class, ['slug' => 'online'])
        ->call('escolherHora', '2026-08-13 10:00:00')
        ->set('nome', 'Joana')
        ->set('telefone', '41999887766')
        ->call('confirmar')
        ->assertSee('É por vídeo');

    expect(Meeting::withoutGlobalScope('tenant')->count())->toBe(1);
});

it('a caixinha do video fica guardada no link', function () {
    Livewire::actingAs($this->eu)->test(LinksDeAgendamento::class)
        ->call('novo')
        ->set('titulo', 'Consulta online')
        ->set('por_video', true)
        ->call('salvar');

    $pagina = BookingPage::where('titulo', 'Consulta online')->first();

    expect($pagina->por_video)->toBeTrue();

    Livewire::actingAs($this->eu)->test(LinksDeAgendamento::class)
        ->call('editar', $pagina->id)
        ->assertSet('por_video', true);
});
