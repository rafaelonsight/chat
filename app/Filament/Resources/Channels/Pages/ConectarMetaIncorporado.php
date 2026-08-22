<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use Filament\Resources\Pages\Page;

/**
 * O botao "Continuar com o Facebook" do Cadastro Incorporado.
 *
 * Substitui, para quem tem esse caminho disponivel, o formulario manual de Phone Number
 * ID / WABA ID / Token: o popup da Meta devolve os tres, e quem confirma sozinho e o
 * JS desta tela (ver a view). O formulario manual continua existindo em
 * create?tipo=meta_cloud, para quem precisar dele (rotacao de token, caso que o
 * Cadastro Incorporado nao cobre).
 */
class ConectarMetaIncorporado extends Page
{
    protected static string $resource = ChannelResource::class;

    protected string $view = 'filament.resources.channels.pages.conectar-meta-incorporado';

    public function getTitle(): string
    {
        return 'Conectar WhatsApp oficial';
    }
}
