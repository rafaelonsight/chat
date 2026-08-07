<?php

use App\Filament\Pages\Agenda;
use App\Models\{Appointment, Contact, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * A agenda: compromisso com o cliente, e lembrete pessoal.
 *
 * SAO DUAS COISAS COM A MESMA ESTRUTURA, e o que muda e QUEM VE.
 *
 *   compromisso -> hora marcada com alguem de fora. A equipe inteira ve, porque quem atende o
 *                  telefone precisa saber que o colega vai la as 14h.
 *   lembrete    -> "cobrar esse cliente amanha". So quem criou ve. Lembrete alheio na tela de
 *                  todo mundo vira ruido, e ruido faz a agenda inteira ser ignorada.
 *
 * A TELA E ORGANIZADA POR URGENCIA, e nao por calendario: quem abre de manha quer saber o que
 * esta ATRASADO e o que e HOJE — nao o que tem no dia 23. E o badge do menu conta so o
 * atrasado, porque badge que acende por algo ainda no prazo e badge que se aprende a ignorar.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'agenda']);
    TenantContext::set($this->conta->id);

    $this->eu = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Eu',
        'email' => 'eu@agenda.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->colega = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Colega',
        'email' => 'colega@agenda.test', 'password' => 'segredo123',
    ]);

    $this->actingAs($this->eu);
});

afterEach(fn () => TenantContext::forget());

function marca($ctx, array $dados = []): Appointment
{
    return Appointment::create($dados + [
        'tenant_id'  => $ctx->conta->id,
        'user_id'    => $ctx->eu->id,
        'criado_por' => $ctx->eu->id,
        'tipo'       => Appointment::COMPROMISSO,
        'titulo'     => 'Visita',
        'comeca_em'  => now()->addHours(3),
    ]);
}

// ------------------------------------------------------------------- quem ve

it('compromisso do colega aparece para mim', function () {
    // Quem atende o telefone precisa saber que o colega vai la as 14h.
    marca($this, ['user_id' => $this->colega->id, 'titulo' => 'Visita do colega']);

    expect(Appointment::visivelPara($this->eu)->count())->toBe(1);
});

it('lembrete do colega nao aparece para mim', function () {
    marca($this, [
        'user_id' => $this->colega->id,
        'tipo'    => Appointment::LEMBRETE,
        'titulo'  => 'Cobrar o fulano',
    ]);

    expect(Appointment::visivelPara($this->eu)->count())->toBe(0)
        ->and(Appointment::visivelPara($this->colega)->count())->toBe(1);
});

it('sem ninguem logado nao ve nada', function () {
    marca($this);

    expect(Appointment::visivelPara(null)->count())->toBe(0);
});

it('nao mistura conta', function () {
    marca($this);

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'agenda-outra']);
    TenantContext::set($outra->id);
    $dela = User::create([
        'tenant_id' => $outra->id, 'name' => 'Dela',
        'email' => 'dela@agenda.test', 'password' => 'segredo123',
    ]);

    expect(Appointment::visivelPara($dela)->count())->toBe(0);
});

// -------------------------------------------------------------------- marcar

it('marca um compromisso pela tela', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Instalar na loja')
        ->set('quando', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('duracao_min', 90)
        ->call('salvar')
        ->assertSet('formAberto', false);

    $a = Appointment::first();

    expect($a->titulo)->toBe('Instalar na loja')
        ->and($a->tipo)->toBe(Appointment::COMPROMISSO)
        ->and($a->duracao_min)->toBe(90)
        ->and($a->user_id)->toBe($this->eu->id)
        ->and($a->criado_por)->toBe($this->eu->id);
});

it('marca compromisso para o colega', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', 'Visita')
        ->set('user_id', $this->colega->id)
        ->call('salvar');

    expect(Appointment::first()->user_id)->toBe($this->colega->id);
});

it('lembrete e sempre de quem escreveu', function () {
    // Lembrete para outra pessoa seria por na cabeca dela uma coisa que ela nunca vai ver,
    // porque lembrete alheio nao aparece.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('tipo', Appointment::LEMBRETE)
        ->set('titulo', 'Ligar para o contador')
        ->set('user_id', $this->colega->id)
        ->call('salvar');

    $a = Appointment::first();

    expect($a->user_id)->toBe($this->eu->id)
        ->and($a->duracao_min)->toBeNull();
});

it('sem titulo nao salva', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('titulo', '')
        ->call('salvar')
        ->assertHasErrors('titulo');

    expect(Appointment::count())->toBe(0);
});

it('marca sem contato nenhum', function () {
    // "Ligar para o contador" nao tem contato cadastrado, e exigir um faria a pessoa inventar
    // cadastro para conseguir anotar.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')->set('titulo', 'Ligar para o contador')->call('salvar');

    expect(Appointment::first()->contact_id)->toBeNull();
});

it('acha o contato pelo nome e prende no compromisso', function () {
    $ct = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Padaria do Ze',
        'telefone_e164' => '+5541988887777',
    ]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novo')
        ->set('buscaContato', 'Padaria')
        ->assertViewHas('candidatos', fn ($c) => $c->count() === 1)
        ->call('escolherContato', $ct->id)
        ->set('titulo', 'Levar o contrato')
        ->call('salvar');

    expect(Appointment::first()->contact_id)->toBe($ct->id);
});

// -------------------------------------------------------------------- a tela

it('separa atrasado de hoje e do resto', function () {
    // Relogio parado no meio da manha: com a hora da maquina, "daqui a duas horas" vira
    // amanha se o teste rodar as 23h.
    $this->travelTo(now()->startOfDay()->addHours(9));

    marca($this, ['titulo' => 'Ficou para tras', 'comeca_em' => now()->subHours(2)]);
    marca($this, ['titulo' => 'Daqui a pouco', 'comeca_em' => now()->addHours(2)]);
    marca($this, ['titulo' => 'Semana que vem', 'comeca_em' => now()->addDays(6)]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->assertViewHas('grupos', function ($g) {
            return $g['Atrasados']->count() === 1
                && $g['Hoje']->count() === 1
                && $g['Depois']->count() === 1
                && ! array_key_exists('Amanhã', $g);
        });
});

it('o numero no menu conta so o que esta atrasado', function () {
    // Badge que acende por algo ainda no prazo e badge que se aprende a ignorar.
    marca($this, ['comeca_em' => now()->subHour()]);
    marca($this, ['comeca_em' => now()->addHours(4)]);

    expect(Agenda::getNavigationBadge())->toBe('1');
});

it('o que ja foi feito nao acende o menu', function () {
    marca($this, ['comeca_em' => now()->subHour(), 'concluido_em' => now()]);

    expect(Agenda::getNavigationBadge())->toBeNull();
});

it('lembrete do colega nao entra no meu numero', function () {
    marca($this, [
        'user_id' => $this->colega->id, 'tipo' => Appointment::LEMBRETE,
        'comeca_em' => now()->subHour(),
    ]);

    expect(Agenda::getNavigationBadge())->toBeNull();
});

// -------------------------------------------------------------------- acoes

it('marca como feito e desmarca de novo', function () {
    $a = marca($this);

    $tela = Livewire::actingAs($this->eu)->test(Agenda::class);

    $tela->call('concluir', $a->id);
    expect($a->refresh()->concluido())->toBeTrue();

    $tela->call('concluir', $a->id);
    expect($a->refresh()->concluido())->toBeFalse();
});

it('edita o que ja estava marcado', function () {
    $a = marca($this);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $a->id)
        ->assertSet('titulo', 'Visita')
        ->set('titulo', 'Visita remarcada')
        ->call('salvar');

    expect($a->refresh()->titulo)->toBe('Visita remarcada')
        ->and(Appointment::count())->toBe(1);
});

it('exclui', function () {
    $a = marca($this);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('excluir', $a->id);

    expect(Appointment::count())->toBe(0);
});

it('nao mexe no lembrete alheio nem sabendo o id', function () {
    // A defesa esta na consulta, e nao no menu: o id chega de fora.
    $dele = marca($this, ['user_id' => $this->colega->id, 'tipo' => Appointment::LEMBRETE]);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('excluir', $dele->id);

    expect(Appointment::count())->toBe(1);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('concluir', $dele->id);

    expect($dele->refresh()->concluido())->toBeFalse();
});

// --------------------------------------------------------------------- menu

it('fica dentro de CRM e a tela abre', function () {
    expect(Agenda::getNavigationGroup())->toBe('CRM');

    $this->withoutExceptionHandling();
    $this->withSession(['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $this->eu->id])
        ->get('/admin/agenda')
        ->assertSuccessful()
        ->assertSee('Nada marcado');
});
