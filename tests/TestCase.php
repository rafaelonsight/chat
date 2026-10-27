<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // A suite apaga e recria tabelas: so pode rodar no banco e no papel isolados de teste.
        if (! $app->environment('testing')
            || config('database.connections.pgsql.database') !== 'virtus_final_test'
            || config('database.connections.pgsql.username') !== 'virtus_final_test') {
            throw new \RuntimeException('Tests require the isolated test database and role (virtus_final_test).');
        }

        Http::preventStrayRequests();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * A suíte histórica cria atores sem vínculos para cenários que não testam autorização.
         * Em produção isso mudou: somente Super Admin ignora a matriz de canais.
         * Promover esses atores SOMENTE NO AMBIENTE DE TESTE mantém cada teste focado
         * no comportamento que pretende provar. Testes de autorização rebaixam explicitamente
         * seus atores e continuam protegendo a regra real.
         *
         * Fora da edição Agenda: os testes dela conferem a própria matriz de acesso.
         */
        if (config('agenda.edition')) {
            return;
        }

        User::created(function (User $user): void {
            if (! $user->super_admin) {
                $user->forceFill(['super_admin' => true])->saveQuietly();
            }
        });
    }
}
