<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * O item "Cadastro" do ERP.
 *
 * POR QUE UMA PAGINA E NAO SO UM ROTULO: o menu do Filament tem dois niveis por padrao, e o
 * terceiro se faz apontando itens para um item PAI — que precisa existir de verdade. Entao
 * "Cadastro" e uma pagina, e Produtos e Pessoas se penduram nela.
 *
 * E ela nao e uma pagina vazia de enfeite: quem clica em Cadastro quer chegar a algum lugar, e
 * dois atalhos grandes resolvem melhor que um "escolha no menu ao lado".
 */
class CadastroErp extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'ERP';

    protected static ?string $navigationLabel = 'Cadastro';

    protected static ?string $title = 'Cadastro';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'cadastro-erp';

    protected string $view = 'filament.pages.cadastro-erp';
}
