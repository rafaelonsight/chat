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
 * E UM CALENDARIO DE VERDADE — mes, semana, dia — porque e assim que as pessoas ja sabem ler
 * uma agenda. A grade e o que mostra o BURACO: uma lista diz o que tem marcado, so a grade
 * responde "cabe uma visita as 15h?", que e a pergunta de quem esta com o cliente no telefone.
 *
 * A VISAO LISTA continua, agrupada por urgencia, porque atraso nao e uma data — e uma
 * comparacao com agora, e nao existe celula no calendario onde ele caiba.
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

/** A coluna de um dia dentro da grade de horas. */
function colunaDe(array $colunas, string $dia): array
{
    foreach ($colunas as $c) {
        if ($c['data'] === $dia) {
            return $c;
        }
    }

    return ['blocos' => []];
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

it('o calendario anda ate onde a coisa foi marcada', function () {
    // Salvar e nao ver o que salvou parece que nao salvou.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->set('cursor', '2026-08-10')
        ->call('novo')
        ->set('titulo', 'La na frente')
        ->set('quando', '2026-11-20T14:00')
        ->call('salvar')
        ->assertSet('cursor', '2026-11-20');
});

// ------------------------------------------------------------- clicar no vazio

it('clicar num buraco da grade ja traz o dia e a hora', function () {
    // Quem clica nas 15h de quinta ja disse quando quer; pedir a data de novo num formulario e
    // perguntar o que a pessoa acabou de responder.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novoEm', '2026-08-13', 15 * 60)
        ->assertSet('formAberto', true)
        ->assertSet('quando', '2026-08-13T15:00');
});

it('clicar numa celula do mes cai nas nove da manha', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('novoEm', '2026-08-13')
        ->assertSet('quando', '2026-08-13T09:00');
});

// ---------------------------------------------------------------- navegacao

it('anda de semana em semana', function () {
    $tela = Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'semana')
        ->set('cursor', '2026-08-05');

    $tela->call('proximo')->assertSet('cursor', '2026-08-12');
    $tela->call('anterior')->assertSet('cursor', '2026-08-05');
});

it('anda de mes em mes sem escorregar de dia', function () {
    // 31 de janeiro mais um mes e 28 de fevereiro, e nao 3 de marco.
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'mes')
        ->set('cursor', '2026-01-31')
        ->call('proximo')
        ->assertSet('cursor', '2026-02-28');
});

it('o botao hoje volta para hoje', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->set('cursor', '2020-01-01')
        ->call('hoje')
        ->assertSet('cursor', now()->toDateString());
});

it('a visao escolhida gruda entre uma visita e outra', function () {
    // Quem trabalha no mes nao quer reescolher toda vez que abre.
    Livewire::actingAs($this->eu)->test(Agenda::class)->call('verComo', 'mes');

    Livewire::actingAs($this->eu)->test(Agenda::class)->assertSet('visao', 'mes');
});

it('visao inventada nao troca nada', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'trimestre')
        ->assertSet('visao', 'semana');
});

it('celula cheia no mes abre o dia', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verDia', '2026-08-13')
        ->assertSet('visao', 'dia')
        ->assertSet('cursor', '2026-08-13');
});

// -------------------------------------------------------------------- a grade

it('a semana traz os sete dias, de domingo a sabado', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'semana')
        ->set('cursor', '2026-08-05')
        ->assertViewHas('colunas', function ($c) {
            return count($c) === 7
                && $c[0]['data'] === '2026-08-02'
                && $c[6]['data'] === '2026-08-08';
        });
});

it('poe o bloco na altura da hora em que ele comeca', function () {
    marca($this, ['comeca_em' => '2026-08-05 12:00:00', 'duracao_min' => 60]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'dia')
        ->set('cursor', '2026-08-05')
        ->assertViewHas('colunas', function ($c) {
            $b = $c[0]['blocos'][0];

            // meio-dia e metade do dia; uma hora e 1/24 dele
            return abs($b['topo'] - 50) < 0.01
                && abs($b['altura'] - 100 / 24) < 0.01
                && $b['larg'] === 100.0;
        });
});

it('duas coisas na mesma hora dividem a largura', function () {
    // Uma escondendo a outra faria a grade mentir justamente na hora em que a resposta importa.
    marca($this, ['comeca_em' => '2026-08-05 10:00:00', 'duracao_min' => 60, 'titulo' => 'A']);
    marca($this, ['comeca_em' => '2026-08-05 10:30:00', 'duracao_min' => 60, 'titulo' => 'B']);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'dia')
        ->set('cursor', '2026-08-05')
        ->assertViewHas('colunas', function ($c) {
            $b = $c[0]['blocos'];

            return count($b) === 2
                && $b[0]['larg'] === 50.0 && $b[0]['esq'] === 0.0
                && $b[1]['larg'] === 50.0 && $b[1]['esq'] === 50.0;
        });
});

it('o que nao se cruza ocupa a coluna inteira', function () {
    marca($this, ['comeca_em' => '2026-08-05 08:00:00', 'duracao_min' => 60]);
    marca($this, ['comeca_em' => '2026-08-05 15:00:00', 'duracao_min' => 60]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'dia')
        ->set('cursor', '2026-08-05')
        ->assertViewHas('colunas', fn ($c) => collect($c[0]['blocos'])->every(fn ($b) => $b['larg'] === 100.0));
});

it('compromisso de dez minutos ainda cabe o texto', function () {
    marca($this, ['comeca_em' => '2026-08-05 09:00:00', 'duracao_min' => 10]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'dia')
        ->set('cursor', '2026-08-05')
        ->assertViewHas('colunas', fn ($c) => abs($c[0]['blocos'][0]['altura'] - 30 / 1440 * 100) < 0.01);
});

it('o mes fecha em semanas inteiras, de domingo a sabado', function () {
    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'mes')
        ->set('cursor', '2026-08-15')
        ->assertViewHas('semanas', function ($s) {
            $todasCheias = collect($s)->every(fn ($linha) => count($linha) === 7);

            // Agosto de 2026 comeca num sabado: a folha abre no domingo anterior, dia 26.
            return $todasCheias && $s[0][0]['data'] === '2026-07-26' && ! $s[0][0]['noMes'];
        });
});

it('o mes poe cada coisa no seu dia', function () {
    marca($this, ['comeca_em' => '2026-08-15 10:00:00', 'titulo' => 'No dia quinze']);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'mes')
        ->set('cursor', '2026-08-01')
        ->assertViewHas('semanas', function ($s) {
            $comItem = collect($s)->flatten(1)->filter(fn ($d) => $d['itens']->isNotEmpty());

            return $comItem->count() === 1 && $comItem->first()['data'] === '2026-08-15';
        });
});

it('a grade so traz o periodo que esta na tela', function () {
    marca($this, ['comeca_em' => '2026-08-05 10:00:00']);
    marca($this, ['comeca_em' => '2026-12-05 10:00:00']);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'semana')
        ->set('cursor', '2026-08-05')
        ->assertViewHas('colunas', fn ($c) => collect($c)->sum(fn ($d) => count($d['blocos'])) === 1);
});

// -------------------------------------------------------------------- filtro

it('filtra a agenda por pessoa', function () {
    marca($this, ['titulo' => 'Minha']);
    marca($this, ['user_id' => $this->colega->id, 'titulo' => 'Do colega']);

    $tela = Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'lista');

    $tela->assertViewHas('grupos', fn ($g) => collect($g)->flatten()->count() === 2);

    $tela->set('quem', $this->colega->id)
        ->assertViewHas('grupos', function ($g) {
            $itens = collect($g)->flatten();

            return $itens->count() === 1 && $itens->first()->titulo === 'Do colega';
        });
});

// -------------------------------------------------------------------- a lista

it('separa atrasado de hoje e do resto', function () {
    // Relogio parado no meio da manha: com a hora da maquina, "daqui a duas horas" vira
    // amanha se o teste rodar as 23h.
    $this->travelTo(now()->startOfDay()->addHours(9));

    marca($this, ['titulo' => 'Ficou para tras', 'comeca_em' => now()->subHours(2)]);
    marca($this, ['titulo' => 'Daqui a pouco', 'comeca_em' => now()->addHours(2)]);
    marca($this, ['titulo' => 'Semana que vem', 'comeca_em' => now()->addDays(6)]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'lista')
        ->assertViewHas('grupos', function ($g) {
            return $g['Atrasados']->count() === 1
                && $g['Hoje']->count() === 1
                && $g['Depois']->count() === 1
                && ! array_key_exists('Amanhã', $g);
        });
});

it('a lista olha para a frente, e nao para a semana na tela', function () {
    // Atraso nao e uma data: e uma comparacao com agora, e nao ha celula no calendario onde
    // ele caiba.
    marca($this, ['comeca_em' => now()->addMonths(4)]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('verComo', 'lista')
        ->set('cursor', now()->toDateString())
        ->assertViewHas('grupos', fn ($g) => collect($g)->flatten()->count() === 1);
});

// -------------------------------------------------------------------- o menu

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

it('arrasta para outro dia e a hora fica onde estava', function () {
    // Quem arrasta de terca para quinta quer mudar o DIA, nao a hora.
    $a = marca($this, ['comeca_em' => '2026-08-05 14:30:00']);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('mover', $a->id, '2026-08-07');

    expect($a->refresh()->comeca_em->format('Y-m-d H:i'))->toBe('2026-08-07 14:30');
});

it('arrasta para outra hora dentro da grade', function () {
    $a = marca($this, ['comeca_em' => '2026-08-05 09:00:00']);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('mover', $a->id, '2026-08-05', 16 * 60 + 30);

    expect($a->refresh()->comeca_em->format('Y-m-d H:i'))->toBe('2026-08-05 16:30');
});

it('nao arrasta o lembrete alheio nem sabendo o id', function () {
    // A defesa esta na consulta, e nao no menu: o id chega de fora.
    $dele = marca($this, [
        'user_id' => $this->colega->id, 'tipo' => Appointment::LEMBRETE,
        'comeca_em' => '2026-08-05 09:00:00',
    ]);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('mover', $dele->id, '2026-12-25', 60);

    expect($dele->refresh()->comeca_em->format('Y-m-d'))->toBe('2026-08-05');
});

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

it('nao abre para editar o lembrete alheio', function () {
    $dele = marca($this, ['user_id' => $this->colega->id, 'tipo' => Appointment::LEMBRETE]);

    Livewire::actingAs($this->eu)->test(Agenda::class)
        ->call('editar', $dele->id)
        ->assertSet('formAberto', false);
});

it('exclui', function () {
    $a = marca($this);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('excluir', $a->id);

    expect(Appointment::count())->toBe(0);
});

it('nao mexe no lembrete alheio nem sabendo o id', function () {
    $dele = marca($this, ['user_id' => $this->colega->id, 'tipo' => Appointment::LEMBRETE]);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('excluir', $dele->id);

    expect(Appointment::count())->toBe(1);

    Livewire::actingAs($this->eu)->test(Agenda::class)->call('concluir', $dele->id);

    expect($dele->refresh()->concluido())->toBeFalse();
});

// --------------------------------------------------------------------- menu

it('fica dentro de CRM e a tela abre como calendario', function () {
    expect(Agenda::getNavigationGroup())->toBe('CRM');

    marca($this, ['comeca_em' => now()->startOfDay()->addHours(10), 'titulo' => 'Visita de hoje']);

    $this->withoutExceptionHandling();
    $this->withSession(['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $this->eu->id])
        ->get('/admin/agenda')
        ->assertSuccessful()
        ->assertSee('Semana')
        ->assertSee('Visita de hoje')
        // a grade de horas existe de verdade, e nao so o cabecalho
        ->assertSee('23:00');
});

it('a visao lista tambem abre', function () {
    $this->withoutExceptionHandling();
    $this->withSession([
        'login_web_'.sha1('Illuminate\Auth\SessionGuard') => $this->eu->id,
        'agenda.visao' => 'lista',
    ])->get('/admin/agenda')->assertSuccessful()->assertSee('Nada marcado');
});

it('a visao mes tambem abre', function () {
    $this->withoutExceptionHandling();
    $this->withSession([
        'login_web_'.sha1('Illuminate\Auth\SessionGuard') => $this->eu->id,
        'agenda.visao' => 'mes',
    ])->get('/admin/agenda')->assertSuccessful()->assertSee('qua');
});
