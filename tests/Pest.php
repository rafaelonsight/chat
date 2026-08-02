<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Todo teste de Feature e Unit roda dentro da aplicacao e com o banco limpo.
// RefreshDatabase usa transacao por teste: nunca escreve de verdade na base.
uses(TestCase::class, RefreshDatabase::class)->in('Feature', 'Unit');
