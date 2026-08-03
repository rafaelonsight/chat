<?php

use App\Models\{BusinessHour, BusinessHourException, Channel, Tenant};
use App\Services\BusinessHours;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

function contaHorario(string $slug, string $fuso = 'America/Sao_Paulo'): Tenant
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug, 'fuso_horario' => $fuso]);
    TenantContext::set($t->id);

    return $t;
}

// grade comercial: 08:30-12:00 e 13:00-18:00 de segunda a sabado, domingo fechado
function gradeComercial(?int $channelId = null): void
{
    foreach (range(0, 6) as $dia) {
        BusinessHour::create([
            'channel_id' => $channelId,
            'dia_semana' => $dia,
            'ativo'      => $dia !== 0,
            'intervalos' => [
                ['inicio' => '08:30', 'fim' => '12:00'],
                ['inicio' => '13:00', 'fim' => '18:00'],
            ],
        ]);
    }
}

function emSP(string $quando): Carbon
{
    return Carbon::parse($quando, 'America/Sao_Paulo');
}

afterEach(fn () => TenantContext::forget());

// ------------------------------------------------- sem configuracao: inerte

it('sem grade configurada, considera sempre aberto', function () {
    $t = contaHorario('hr0');
    $h = new BusinessHours($t);

    expect($h->abertoEm(emSP('2026-08-05 03:00')))->toBeTrue()
        ->and($h->abertoEm(emSP('2026-08-09 23:59')))->toBeTrue();
});

it('sem grade, minutos uteis igualam o relogio de parede', function () {
    $t = contaHorario('hr1');
    $h = new BusinessHours($t);

    expect($h->minutosUteisEntre(emSP('2026-08-04 22:00'), emSP('2026-08-05 08:00')))->toBe(600);
});

// ----------------------------------------------------------- aberto ou nao

it('reconhece dentro e fora do horario', function () {
    $t = contaHorario('hr2');
    gradeComercial();
    $h = new BusinessHours($t);

    // 2026-08-05 e uma quarta-feira
    expect($h->abertoEm(emSP('2026-08-05 09:00')))->toBeTrue()
        ->and($h->abertoEm(emSP('2026-08-05 14:30')))->toBeTrue()
        ->and($h->abertoEm(emSP('2026-08-05 08:00')))->toBeFalse()
        ->and($h->abertoEm(emSP('2026-08-05 18:30')))->toBeFalse()
        ->and($h->abertoEm(emSP('2026-08-05 23:00')))->toBeFalse();
});

it('almoco conta como fechado', function () {
    $t = contaHorario('hr3');
    gradeComercial();
    $h = new BusinessHours($t);

    expect($h->abertoEm(emSP('2026-08-05 12:30')))->toBeFalse()
        ->and($h->abertoEm(emSP('2026-08-05 11:59')))->toBeTrue()
        ->and($h->abertoEm(emSP('2026-08-05 13:01')))->toBeTrue();
});

it('dia desativado fica fechado o dia todo', function () {
    $t = contaHorario('hr4');
    gradeComercial();
    $h = new BusinessHours($t);

    // 2026-08-09 e domingo
    expect($h->abertoEm(emSP('2026-08-09 10:00')))->toBeFalse();
});

it('respeita o fuso da conta', function () {
    $t = contaHorario('hr5', 'America/Manaus'); // GMT-4
    gradeComercial();
    $h = new BusinessHours($t);

    // 13:00 UTC = 09:00 em Manaus: aberto
    expect($h->abertoEm(Carbon::parse('2026-08-05 13:00', 'UTC')))->toBeTrue();
    // 11:00 UTC = 07:00 em Manaus: fechado
    expect($h->abertoEm(Carbon::parse('2026-08-05 11:00', 'UTC')))->toBeFalse();
});

it('suporta plantao que cruza a meia-noite', function () {
    $t = contaHorario('hr6');
    foreach (range(0, 6) as $dia) {
        BusinessHour::create([
            'dia_semana' => $dia,
            'ativo'      => true,
            'intervalos' => [['inicio' => '22:00', 'fim' => '02:00']],
        ]);
    }
    $h = new BusinessHours($t);

    expect($h->abertoEm(emSP('2026-08-05 23:30')))->toBeTrue()
        ->and($h->abertoEm(emSP('2026-08-06 01:30')))->toBeTrue()
        ->and($h->abertoEm(emSP('2026-08-06 03:00')))->toBeFalse();
});

it('canal pode ter grade propria que sobrepoe a da conta', function () {
    $t = contaHorario('hr7');
    gradeComercial();

    $suporte = Channel::create(['nome' => 'Suporte 24h']);
    $suporte->refresh();
    foreach (range(0, 6) as $dia) {
        BusinessHour::create([
            'channel_id' => $suporte->id,
            'dia_semana' => $dia,
            'ativo'      => true,
            'intervalos' => [['inicio' => '00:00', 'fim' => '23:59']],
        ]);
    }

    $h = new BusinessHours($t);

    expect($h->abertoEm(emSP('2026-08-05 03:00')))->toBeFalse()
        ->and($h->abertoEm(emSP('2026-08-05 03:00'), $suporte))->toBeTrue();
});

// ------------------------------------------------------------- excecoes

it('excecao fecha o dia mesmo com grade ativa', function () {
    $t = contaHorario('hr8');
    gradeComercial();
    BusinessHourException::create(['data' => '2026-12-25', 'fechado' => true, 'descricao' => 'Natal']);

    $h = new BusinessHours($t);

    // 2026-12-25 e uma sexta-feira
    expect($h->abertoEm(emSP('2026-12-25 10:00')))->toBeFalse()
        ->and($h->abertoEm(emSP('2026-12-24 10:00')))->toBeTrue();
});

it('excecao pode definir horario especial em vez de fechar', function () {
    $t = contaHorario('hr9');
    gradeComercial();
    BusinessHourException::create([
        'data'       => '2026-12-24',
        'fechado'    => false,
        'intervalos' => [['inicio' => '08:00', 'fim' => '12:00']],
        'descricao'  => 'Vespera de Natal',
    ]);

    $h = new BusinessHours($t);

    expect($h->abertoEm(emSP('2026-12-24 09:00')))->toBeTrue()
        ->and($h->abertoEm(emSP('2026-12-24 15:00')))->toBeFalse();
});

// ------------------------------------------------------- proxima abertura

it('diz quando abre de novo', function () {
    $t = contaHorario('hra');
    gradeComercial();
    $h = new BusinessHours($t);

    // sabado 18:30 -> proxima e segunda 08:30 (domingo fechado)
    $proxima = $h->proximaAbertura(emSP('2026-08-08 18:30'));
    expect($proxima->format('Y-m-d H:i'))->toBe('2026-08-10 08:30');

    // quarta 12:30 (almoco) -> volta 13:00
    expect($h->proximaAbertura(emSP('2026-08-05 12:30'))->format('Y-m-d H:i'))->toBe('2026-08-05 13:00');

    // quarta 07:00 -> abre 08:30
    expect($h->proximaAbertura(emSP('2026-08-05 07:00'))->format('Y-m-d H:i'))->toBe('2026-08-05 08:30');
});

it('proxima abertura em linguagem de gente', function () {
    $t = contaHorario('hrb');
    gradeComercial();
    $h = new BusinessHours($t);

    expect($h->proximaAberturaLegivel(emSP('2026-08-05 07:00')))->toContain('08:30')
        ->and($h->proximaAberturaLegivel(emSP('2026-08-05 12:30')))->toContain('13:00');
});

// ----------------------------------------------------------- minutos uteis

it('conta so o tempo dentro do horario', function () {
    $t = contaHorario('hrc');
    gradeComercial();
    $h = new BusinessHours($t);

    // 23:00 de quarta ate 08:35 de quinta: 5 minutos uteis, nao 9h35
    expect($h->minutosUteisEntre(emSP('2026-08-05 23:00'), emSP('2026-08-06 08:35')))->toBe(5);

    // 09:00 as 10:00 dentro do horario: 60
    expect($h->minutosUteisEntre(emSP('2026-08-05 09:00'), emSP('2026-08-05 10:00')))->toBe(60);

    // 11:30 as 13:30 atravessa o almoco: 30 + 30 = 60
    expect($h->minutosUteisEntre(emSP('2026-08-05 11:30'), emSP('2026-08-05 13:30')))->toBe(60);
});

it('pula domingo ao contar minutos uteis', function () {
    $t = contaHorario('hrd');
    gradeComercial();
    $h = new BusinessHours($t);

    // sabado 17:30 ate segunda 09:00: 30 min de sabado + 30 de segunda
    expect($h->minutosUteisEntre(emSP('2026-08-08 17:30'), emSP('2026-08-10 09:00')))->toBe(60);
});

it('minutos uteis nunca negativo', function () {
    $t = contaHorario('hre');
    gradeComercial();
    $h = new BusinessHours($t);

    expect($h->minutosUteisEntre(emSP('2026-08-05 10:00'), emSP('2026-08-05 09:00')))->toBe(0);
});
