<?php

namespace App\Services\Agendamento;

use RuntimeException;

/** Alguem confirmou aquele horario primeiro. */
class VagaTomada extends RuntimeException
{
    public function __construct(string $mensagem = 'Esse horário acabou de ser reservado por outra pessoa. Escolha outro.')
    {
        parent::__construct($mensagem);
    }
}
