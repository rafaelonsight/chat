<?php

namespace App\Exceptions;

/**
 * Tentaram apagar a equipe padrao da conta.
 *
 * Excecao PROPRIA e nao uma genérica: quem chama precisa poder distinguir "nao deu para apagar
 * porque e a padrao" de "nao deu para apagar porque o banco caiu". A tela mostra a mensagem
 * desta; da outra, mostra que algo quebrou.
 */
class EquipePadraoProtegida extends \RuntimeException
{
}
