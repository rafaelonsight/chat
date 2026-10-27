<?php

namespace Tests;

/*
 * Os testes da pasta Agenda rodam com a edicao Agenda ligada, como ela roda em agenda.virtus.chat.
 *
 * A chave tem de existir ANTES de a aplicacao subir: ela decide quais rotas e qual painel sao
 * registrados no boot, e um config() dentro do teste chegaria tarde. E sai no tearDown, senao o
 * proximo teste de Feature herdaria a edicao Agenda sem pedir.
 *
 * Provisorio: some na Fase 2 do blueprint, quando a Agenda virar modulo por conta.
 */
abstract class AgendaTestCase extends TestCase
{
    public function createApplication()
    {
        putenv('AGENDA_EDITION=true');
        $_ENV['AGENDA_EDITION'] = $_SERVER['AGENDA_EDITION'] = 'true';

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('AGENDA_EDITION');
        unset($_ENV['AGENDA_EDITION'], $_SERVER['AGENDA_EDITION']);
    }
}
