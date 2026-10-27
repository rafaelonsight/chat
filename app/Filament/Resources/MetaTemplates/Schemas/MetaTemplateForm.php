<?php
namespace App\Filament\Resources\MetaTemplates\Schemas;

use App\Models\{Channel, Tenant};
use App\Support\TenantContext;
use Filament\Forms\Components\{TextInput, Textarea, Select, Repeater};
use Filament\Schemas\Components\{Wizard, Wizard\Step, Text};
use Filament\Schemas\Schema;

class MetaTemplateForm
{
    public static function steps(): array
    {
        return [
            Step::make(__('Identificação'))->icon('heroicon-o-building-office')->schema([
                Select::make('channel_id')->label(__('Conta WhatsApp'))->required()->searchable()->options(fn() => Channel::where('tipo', Channel::META_CLOUD)->whereNotNull('meta_waba_id')->pluck('nome','id')->all())
                    ->helperText(__('Todos os números da mesma conta WhatsApp compartilham estes templates.')),
                TextInput::make('nome')->label(__('Nome do template'))->required()->maxLength(512)->regex('/^[a-z][a-z0-9_]*$/')->placeholder('la_group_greeting')->helperText(__('Use letras minúsculas, números e sublinhado.')),
            ]),
            Step::make(__('Categoria e idioma'))->icon('heroicon-o-language')->schema([
                Select::make('categoria')->label(__('Categoria'))->options(['UTILITY'=>__('Utilidade — avisos sobre um pedido ou serviço'), 'MARKETING'=>__('Marketing — iniciar contato, ofertas e campanhas'), 'AUTHENTICATION'=>__('Autenticação — código de verificação')])->default('MARKETING')->required()->live(),
                Select::make('idioma')->label(__('Idioma da mensagem'))->options(['en_GB'=>'English (UK)', 'pt_BR'=>'Português (Brasil)'])->default(fn() => Tenant::find(TenantContext::get())?->idioma_comunicacoes ?? 'pt_BR')->required()->live(),
                Text::make(__('A Meta analisa a categoria e pode alterá-la. O idioma da mensagem é independente do idioma do painel.')),
            ]),
            Step::make(__('Mensagem'))->icon('heroicon-o-chat-bubble-left-right')->schema([
                Text::make(__('A Meta define o texto do código de autenticação. Este modelo inclui recomendação de segurança, validade de 10 minutos e botão Copiar código. O envio é destinado à integração de verificação.'))->visible(fn($get) => $get('categoria') === 'AUTHENTICATION'),
                TextInput::make('cabecalho')->label(__('Cabeçalho opcional'))->maxLength(60)->visible(fn($get) => $get('categoria') !== 'AUTHENTICATION'),
                Textarea::make('corpo')->label(__('Mensagem'))->rows(5)->maxLength(1024)->required(fn($get) => $get('categoria') !== 'AUTHENTICATION')->live(onBlur:true)->visible(fn($get) => $get('categoria') !== 'AUTHENTICATION')
                    ->helperText(__('Para personalizar, use {{1}}, {{2}} ou nomes como {{nome}}. Não misture os formatos.')),
                TextInput::make('rodape')->label(__('Rodapé opcional'))->maxLength(60)->visible(fn($get) => $get('categoria') !== 'AUTHENTICATION'),
                Textarea::make('exemplos')->label(__('Exemplos das variáveis'))->rows(3)->helperText(__('Um exemplo por linha, na ordem em que cada variável aparece. Use dados fictícios.'))->visible(fn($get) => $get('categoria') !== 'AUTHENTICATION'),
                Repeater::make('botoes')->label(__('Botões'))->defaultItems(0)->maxItems(3)->addActionLabel(__('Adicionar botão'))->schema([
                    Select::make('tipo')->label(__('Tipo'))->options(['QUICK_REPLY'=>__('Resposta rápida'), 'URL'=>__('Abrir site'), 'PHONE_NUMBER'=>__('Ligar')])->required()->live(),
                    TextInput::make('texto')->label(__('Texto do botão'))->required()->maxLength(25),
                    TextInput::make('valor')->label(__('Endereço ou telefone'))->required(fn($get) => $get('tipo') !== 'QUICK_REPLY')->visible(fn($get) => $get('tipo') !== 'QUICK_REPLY'),
                ])->visible(fn($get) => $get('categoria') !== 'AUTHENTICATION'),
            ]),
            Step::make(__('Revisão'))->icon('heroicon-o-eye')->schema([
                Text::make(fn($get) => __('Nome do template').': '.$get('nome')),
                Text::make(fn($get) => __('Idioma da mensagem').': '.$get('idioma')),
                Text::make(fn($get) => $get('categoria') === 'AUTHENTICATION' ? __('Código de verificação com validade de 10 minutos.') : trim(($get('cabecalho') ?? '')."\n\n".($get('corpo') ?? '')."\n\n".($get('rodape') ?? '')))->extraAttributes(['style'=>'white-space:pre-wrap;border-left:3px solid #7047ff;padding:18px;background:#f5f3ff;border-radius:12px;']),
                Text::make(__('Ao salvar, o template ficará como rascunho. Depois use Solicitar aprovação para enviá-lo à Meta. Ele só estará disponível no chat após aprovação.')),
            ]),
        ];
    }
    public static function configure(Schema $schema): Schema { return $schema->components([Wizard::make(self::steps())->columnSpanFull()]); }
}
