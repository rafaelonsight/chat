<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * A suíte histórica cria atores sem vínculos para cenários que não testam autorização.
         * Em produção isso mudou: somente Super Admin ignora a matriz de canais.
         * Promover esses atores SOMENTE NO AMBIENTE DE TESTE mantém cada teste focado
         * no comportamento que pretende provar. Testes de autorização rebaixam explicitamente
         * seus atores e continuam protegendo a regra real.
         */
        User::created(function (User $user): void {
            if (! $user->super_admin) {
                $user->forceFill(['super_admin' => true])->saveQuietly();
            }
        });
    }
}
