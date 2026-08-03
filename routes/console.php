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
