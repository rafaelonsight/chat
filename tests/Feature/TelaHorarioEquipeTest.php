<?php

use App\Filament\Pages\HorarioAtendimento;
use App\Models\{BusinessHour, Team, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'admin' => true]);
    $this->suporte = Team::create(['nome' => 'Suporte']);
});

afterEach(fn () => TenantContext::forget());

it('comeca na conta e lista as equipes como escopo', function () {
    Livewire::actingAs($this->admin)
        ->test(HorarioAtendimento::class)
        ->assertSet('escopo', 'conta')
        ->assertSee('Conta (padrão)')
        ->assertSee('Suporte');
});

it('equipe sem grade propria aparece como herdando', function () {
    BusinessHour::create([
        'dia_semana' => 1,
        'ativo'      => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    Livewire::actingAs($this->admin)
        ->test(HorarioAtendimento::class)
        ->call('trocarEscopo', 'equipe:'.$this->suporte->id)
        ->assertSee('herda o horário da conta')
        // mostra a grade da conta como ponto de partida: e o que a equipe usa hoje
        ->assertSet('dias.1.inicio', '08:00');
});

it('salvar no escopo da equipe cria grade propria sem tocar na da conta', function () {
    BusinessHour::create([
        'dia_semana' => 1,
        'ativo'      => true,
        'intervalos' => [['inicio' => '08:00', 'fim' => '18:00']],
    ]);

    Livewire::actingAs($this->admin)
        ->test(HorarioAtendimento::class)
        ->call('trocarEscopo', 'equipe:'.$this->suporte->id)
        ->set('dias.1.ativo', true)
        ->set('dias.1.inicio', '00:00')
        ->set('dias.1.almoco_inicio', '12:00')
        ->set('dias.1.almoco_fim', '12:00')
        ->set('dias.1.fim', '23:59')
        ->call('salvar')
        ->assertHasNoErrors();

    $daEquipe = BusinessHour::where('team_id', $this->suporte->id)->where('dia_semana', 1)->first();
    $daConta  = BusinessHour::whereNull('team_id')->whereNull('channel_id')->where('dia_semana', 1)->first();

    expect($daEquipe)->not->toBeNull()
        ->and($daEquipe->intervalos)->toHaveCount(1)          // sem pausa: um intervalo unico
        ->and($daEquipe->intervalos[0]['inicio'])->toBe('00:00')
        ->and($daEquipe->intervalos[0]['fim'])->toBe('23:59')
        ->and($daConta->intervalos[0]['inicio'])->toBe('08:00');
});

it('aceita dia sem pausa, como a propria tela promete', function () {
    // Defeito que este teste descobriu: a tela diz "para um dia sem pausa, deixe
    // inicio e fim do almoco iguais", mas a validacao exigia os quatro horarios
    // estritamente crescentes e recusava exatamente esse caso.
    Livewire::actingAs($this->admin)
        ->test(HorarioAtendimento::class)
        ->set('dias.1.ativo', true)
        ->set('dias.1.inicio', '08:00')
        ->set('dias.1.almoco_inicio', '12:00')
        ->set('dias.1.almoco_fim', '12:00')
        ->set('dias.1.fim', '18:00')
        ->call('salvar')
        ->assertHasNoErrors();

    $linha = BusinessHour::whereNull('team_id')->whereNull('channel_id')->where('dia_semana', 1)->first();

    // Campo a campo, e nao o array inteiro: jsonb normaliza a ordem das chaves,
    // entao comparar arrays com === falha por motivo que nao interessa ao teste.
    expect($linha->intervalos)->toHaveCount(1)
        ->and($linha->intervalos[0]['inicio'])->toBe('08:00')
        ->and($linha->intervalos[0]['fim'])->toBe('18:00');
});

it('continua recusando horario fora de ordem', function () {
    Livewire::actingAs($this->admin)
        ->test(HorarioAtendimento::class)
        ->set('dias.1.ativo', true)
        ->set('dias.1.inicio', '18:00')
        ->set('dias.1.almoco_inicio', '12:00')
        ->set('dias.1.almoco_fim', '13:00')
        ->set('dias.1.fim', '08:00')
        ->call('salvar')
        ->assertHasErrors('dias.1.inicio');

    expect(BusinessHour::count())->toBe(0);
});

it('voltar a herdar apaga a grade da equipe', function () {
    BusinessHour::create([
        'team_id'    => $this->suporte->id,
        'dia_semana' => 1,
        'ativo'      => true,
        'intervalos' => [['inicio' => '20:00', 'fim' => '22:00']],
    ]);

    $tela = Livewire::actingAs($this->admin)
        ->test(HorarioAtendimento::class)
        ->call('trocarEscopo', 'equipe:'.$this->suporte->id)
        ->assertSee('grade própria')
        ->call('limparEscopo');

    expect(BusinessHour::where('team_id', $this->suporte->id)->count())->toBe(0);
    $tela->assertSee('herda o horário da conta');
});
