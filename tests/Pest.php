<?php

/*
 * ARMADILHA DO Http::fake, escrita aqui porque ela me pegou QUATRO vezes.
 *
 * Http::fake([...]) chamado uma segunda vez NAO substitui o primeiro. A definicao original
 * vence e a nova e ignorada em silencio — sem erro, sem aviso. O sintoma nunca e "o fake
 * nao funcionou": e um teste que passa pelo motivo errado, ou que falha dizendo que o
 * valor esperado esta nulo.
 *
 * Onde isso aparece: beforeEach define o stub, e um teste especifico quer outra resposta
 * (um 401, uma lista menor, um erro da API). A tentacao e chamar Http::fake de novo.
 *
 * O que fazer: deixe o stub LER de uma propriedade do teste, e troque a propriedade.
 *
 *     // no beforeEach
 *     $this->resposta = ['ok' => true];
 *     $this->status   = 200;
 *     Http::fake(['*' => fn () => Http::response($this->resposta, $this->status)]);
 *
 *     // no teste que precisa de outra coisa
 *     $this->status = 503;
 *
 * Http::fakeSequence() resolve o caso de "primeira chamada uma coisa, segunda outra", mas
 * nao o caso de um teste isolado querer resposta diferente dos demais.
 */


use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Todo teste de Feature e Unit roda dentro da aplicacao e com o banco limpo.
// RefreshDatabase usa transacao por teste: nunca escreve de verdade na base.
uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');
