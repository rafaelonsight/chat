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
    // /admin/perfil e nao /admin/profile: o resto do produto e /admin/cadastro,
    // /admin/clientes, /admin/primeiros-passos. Uma URL em ingles no meio nao quebra nada,
    // mas e o tipo de detalhe que denuncia software montado as pressas.
    protected static ?string $slug = 'perfil';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),

            TextInput::make('email')
                ->label('E-mail')
                ->disabled()
                // dehydrated(false) tira o campo do que e salvo. Sem isto, um campo desabilitado
                // ainda viaja no formulario e o valor do navegador — que da para editar — seria
                // gravado. Desabilitado e enfeite; nao salvar e a garantia.
                ->dehydrated(false)
                ->belowContent('É com ele que você entra. Para trocar, peça ao administrador da conta.'),

            $this->getCurrentPasswordFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    public function getTitle(): string
    {
        return 'Meu perfil';
    }

    public function getHeading(): string
    {
        return 'Meu perfil';
    }
}
