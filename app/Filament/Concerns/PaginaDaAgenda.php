<?php

namespace App\Filament\Concerns;

use Filament\Panel;
use UnitEnum;

/*
 * Pagina da Agenda dentro do painel do chat, como modulo da conta.
 *
 * Na edicao Agenda (agenda.virtus.chat, ate a virada) a pagina vive no painel proprio, com o slug
 * e o grupo de sempre. No chat ela ganha o prefixo agenda/ — "atendimento", "campanhas" e "canais"
 * ja sao rotas do chat — e entra no grupo do proprio modulo.
 */
trait PaginaDaAgenda
{
    use PertenceAoModulo;

    public static function getSlug(?Panel $panel = null): string
    {
        $slug = trim(parent::getSlug($panel), '/');

        if (config('agenda.edition')) {
            return parent::getSlug($panel);
        }

        return 'agenda/'.($slug === '' ? 'inicio' : $slug);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (config('agenda.edition')) {
            return parent::getNavigationGroup();
        }

        return __(static::$modulo === 'financeiro' ? 'Financeiro' : 'Agenda');
    }
}
