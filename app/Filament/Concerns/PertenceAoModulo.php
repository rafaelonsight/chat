<?php

namespace App\Filament\Concerns;

use App\Support\Modulos;

/*
 * Pagina ou recurso que so existe para a conta com o modulo.
 *
 * canAccess decide as duas coisas ao mesmo tempo no Filament: o item some do menu e a rota da 403.
 * A regra propria da tela (perfil, operador) fica em acessoSemModulo(); quem tinha canAccess passa
 * a declarar acessoSemModulo, senao o metodo da classe esconderia este em silencio.
 *
 * A classe declara: protected static string $modulo = 'campanhas';
 */
trait PertenceAoModulo
{
    public static function canAccess(): bool
    {
        return Modulos::ativo(static::$modulo) && static::acessoSemModulo();
    }

    public static function acessoSemModulo(): bool
    {
        return parent::canAccess();
    }
}
