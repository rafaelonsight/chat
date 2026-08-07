<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// De cinco em cinco minutos: frequente para pegar queda rapido, espacado para o
// silencio por chave (30 min) nao ser o unico freio.
Schedule::command('onchat:diagnostico --alertar')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Sequencias: um tique por minuto. O job tem trava de sobreposicao, entao rodada que demorar
// nao empilha com a seguinte.
Schedule::job(new \App\Jobs\AvancarSequencias)->everyMinute();

// Quem sumiu depois de a gente responder. Uma vez por hora basta: a unidade do gatilho e
// hora, e varrer a cada minuto so gastaria consulta para achar o mesmo nada.
Schedule::job(new \App\Jobs\ProcurarSumidos)->hourly();

// A foto do consumo do mes que acabou. No dia 1 as 00h10 — depois da virada, com folga para o
// relogio e para qualquer job atrasado da noite terminar.
Schedule::job(new \App\Jobs\FecharConsumoDoMes)->monthlyOn(1, '00:10');
