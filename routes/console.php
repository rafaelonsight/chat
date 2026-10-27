<?php

if (config('agenda.edition')) {
    require __DIR__.'/agenda-console.php';
    return;
}

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

/*
 * Para onde a Evolution avisa. De cinco em cinco minutos, junto do diagnostico.
 *
 * O endereco mora dentro dela, gravado quando o canal nasceu: mudanca de dominio do painel
 * nao o acompanha, e ela nao reclama — POSTa no endereco velho, recebe redirecionamento e
 * considera entregue. Isto reaponta sozinho e deixa o selo que o diagnostico cobra.
 */
Schedule::command('onchat:conferir-webhooks')
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

Schedule::command('virtus:task-notices')->everyMinute()->withoutOverlapping();

Schedule::command('virtus:alertar-qualidade')->everyMinute()->withoutOverlapping();

// Modulo Agenda: lembretes e follow-up de agendamentos. A rotina passa so pelas contas com o modulo.
Artisan::command('agenda:communications', function () {
    app(\App\Services\AgendaOutreach::class)->tick();
    $this->info('Rotina de comunicações concluída.');
})->purpose('Processar lembretes e follow-up do modulo Agenda');
Schedule::command('agenda:communications')->everyMinute()->withoutOverlapping();

// Reconsulta somente combinações de país e moeda já solicitadas no painel.
Artisan::command('virtus:atualizar-tarifas-meta', function () {
    \App\Models\MetaTarifa::query()->select('mercado','moeda')->distinct()->get()
        ->each(fn($tarifa)=>\App\Jobs\AtualizarTarifasMeta::dispatch($tarifa->mercado,$tarifa->moeda));
})->purpose('Atualizar as tarifas públicas da Meta utilizadas no painel');
Schedule::command('virtus:atualizar-tarifas-meta')->dailyAt('06:00')->withoutOverlapping();
