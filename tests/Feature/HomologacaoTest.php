<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;

/*
 * O QUE A VIRADA NAO PODE PERDER, E O QUE A HOMOLOGACAO NAO PODE VAZAR.
 *
 * A v2 rodava resumo, qualidade e tarifas por timers do systemd; na versao final tudo passa pelo
 * agendador do Laravel. E a homologacao usa dados copiados da producao: e-mail de la nao pode
 * chegar a cliente de verdade.
 */

it('o que a v2 rodava por timer do sistema esta no agendador', function () {
    $comandos = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

    foreach (['virtus:enviar-resumos', 'virtus:alertar-qualidade', 'virtus:atualizar-tarifas-meta'] as $comando) {
        expect($comandos)->toContain($comando);
    }
});

it('com MAIL_SEMPRE_PARA todo e-mail vai so para la', function () {
    config(['mail.default' => 'array', 'mail.sempre_para' => 'homolog@virtus.test']);

    Mail::raw('resumo', fn ($m) => $m->to('cliente@empresa.test')->cc('copia@empresa.test')->bcc('oculta@empresa.test')->subject('Resumo'));

    $enviada = app('mailer')->getSymfonyTransport()->messages()->last();
    expect(collect($enviada->getEnvelope()->getRecipients())->map->getAddress()->all())->toBe(['homolog@virtus.test']);
});

it('sem MAIL_SEMPRE_PARA o e-mail segue para quem foi escrito', function () {
    config(['mail.default' => 'array', 'mail.sempre_para' => null]);

    Mail::raw('resumo', fn ($m) => $m->to('cliente@empresa.test')->subject('Resumo'));

    $enviada = app('mailer')->getSymfonyTransport()->messages()->last();
    expect(collect($enviada->getEnvelope()->getRecipients())->map->getAddress()->all())->toBe(['cliente@empresa.test']);
});
