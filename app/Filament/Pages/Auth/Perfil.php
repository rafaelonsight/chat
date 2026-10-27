<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * "Meu perfil": trocar o proprio nome e a propria senha.
 *
 * Nao existia. Depois de aceitar o convite, a pessoa ficava com aquela senha para sempre — ou
 * tinha de usar "esqueci minha senha" para trocar uma senha que ela lembra, o que e um jeito
 * torto de fazer uma coisa simples.
 *
 * O E-MAIL FICA SO PARA LEITURA, e essa e a unica diferenca em relacao ao que o Filament
 * entrega pronto. Motivo: o e-mail E o login, e nao ha verificacao de endereco novo. Um erro
 * de digitacao aqui tranca a pessoa para fora por dois caminhos ao mesmo tempo — ela nao
 * consegue entrar, e o "esqueci minha senha" vai para um endereco que nao existe. Quem troca
 * e-mail e o administrador da conta, na tela de usuarios, onde o estrago tem quem desfaca.
 *
 * A senha atual e exigida (campo nativo do Filament). Sem isso, quem passasse por um
 * computador destravado trocaria a senha sem saber a antiga e tomaria a conta. O produto ja
 * tem bloqueio de sessao justamente por essa preocupacao; seria incoerente deixar a porta
 * aberta aqui.
 */
class Perfil extends EditProfile
{
    protected function afterSave(): void
    {
        if(auth()->user()->wasChanged('password'))app(\App\Services\AgendaAccess::class)->selfPasswordChanged(auth()->user());
    }
    // /admin/perfil e nao /admin/profile: o resto do produto e /admin/cadastro,
    // /admin/clientes, /admin/primeiros-passos. Uma URL em ingles no meio nao quebra nada,
    // mas e o tipo de detalhe que denuncia software montado as pressas.
    protected static ?string $slug = 'perfil';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent()->label(__('ui_695fc3f31688')),
            TextInput::make('apelido')->label(__('ui_4f284fbc6bfc'))->required()->maxLength(40)->regex("/^[\pL\pN][\pL\pN .'-]*$/u")->helperText(__('ui_2aafb378c5b3')),
            TextInput::make('telefone')->label(__('ui_04e0410282ed'))->tel()->required()->maxLength(30),
            TextInput::make('cargo')->label(__('ui_6c98e86fc49b'))->maxLength(100),
            \App\Forms\Components\PaletaDeCores::make('cor_chat')->label(__('ui_10eb03864ece')),
            \Filament\Schemas\Components\Text::make(new \Illuminate\Support\HtmlString('<a href="/meu-perfil" class="underline">Atualizar foto e apresentação</a>')),


            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),

            TextInput::make('email')
                ->label(__('ui_0d01e2e86669'))
                ->disabled()
                // dehydrated(false) tira o campo do que e salvo. Sem isto, um campo desabilitado
                // ainda viaja no formulario e o valor do navegador — que da para editar — seria
                // gravado. Desabilitado e enfeite; nao salvar e a garantia.
                ->dehydrated(false)
                ->belowContent('É com ele que você entra. Para trocar, peça ao administrador da conta.'),

        ]);
    }

    public function getTitle(): string
    {
        return __('ui_742e3d06caa4');
    }

    public function getHeading(): string
    {
        return __('ui_742e3d06caa4');
    }
}
