<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

// Estende o Dashboard de proposito: assim o Atendimento passa a ser a home do
// painel. E um app de atendimento — a primeira tela e a conversa, nao widgets.
class Atendimento extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Atendimento';

    protected static ?string $title = 'Atendimento';

    protected string $view = 'filament.pages.atendimento';

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /*
     * SEM TITULO NA PAGINA, de proposito.
     *
     * "Atendimento" escrito em 30px no alto da tela nao informa nada: o menu da esquerda ja
     * esta com o item aceso, e quem chegou aqui foi clicando nele. O que ele fazia era comer
     * 100px de altura — titulo, mais os dois vaos de 32px em volta — numa tela que e uma
     * BANCADA DE TRABALHO, nao um documento. Numa tela de notebook isso era um sexto da
     * altura util gasto para repetir o obvio.
     *
     * O nome continua no titulo da aba do navegador (o $title acima), que e onde ele serve
     * para algo: distinguir a aba entre quinze abertas.
     */
    public function getHeading(): string
    {
        return '';
    }

    public function getWidgets(): array
    {
        return [];
    }
}
