<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A raiz do dominio e a porta de entrada do OnChat: leva ao painel, que
     * mostra o login quando nao ha sessao.
     */
    public function test_a_raiz_leva_ao_painel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_o_painel_pede_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_tela_de_login_abre(): void
    {
        $this->get('/admin/login')->assertOk();
    }
}
