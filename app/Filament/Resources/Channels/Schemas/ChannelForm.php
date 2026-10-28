<?php

namespace App\Filament\Resources\Channels\Schemas;

use App\Models\Channel;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('tipo')
                ->label(__('ui_9685bc6facb9'))
                ->options(fn () => \App\Support\Idiomas::opcoes(Channel::TIPOS))
                ->default(Channel::EVOLUTION)
                ->native(false)
                ->required()
                ->live()
                // NAO se troca o tipo de um canal que existe: o tipo decide a regra da
                // janela de 24h, se cabe grupo e por qual API cada conversa saiu. Trocar
                // depois faria o historico mentir sobre o proprio atendimento. Canal novo
                // custa um clique; historico torto nao se desfaz.
                ->disabledOn('edit')
                // Ja escolhido na tela anterior: mostrar de novo faria a escolha parecer
                // inutil. Continua visivel se alguem chegar aqui pela URL sem escolher.
                ->hidden(fn (Get $get) => request()->query('tipo') !== null)
                ->helperText(__('ui_55aeb82ac31a')),

            // Coexistencia e migracao tem impactos diferentes; a Meta define o caminho elegivel.
            Placeholder::make('aviso_oficial')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 '
                    .'dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">'
                    .'<p class="font-semibold">Confira como o número será conectado.</p>'
                    .'<p class="mt-1">Na coexistência oferecida pela Meta, o WhatsApp Business do celular pode continuar funcionando junto com a API. '
                    .'A disponibilidade depende da conta e do número. Se houver migração, confira o impacto no aplicativo antes de concluir.</p></div>'
                ))
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->columnSpanFull(),

            TextInput::make('nome')
                ->label(__('ui_96e023a3235a'))
                ->helperText(__('ui_343a211d5723'))
                ->required()
                ->maxLength(255),

            // ------------------------------------------------------------ oficial
            // Os identificadores ficam NO CANAL e nao na configuracao do servidor: com
            // eles globais, o segundo cliente nao caberia.
            TextInput::make('meta_phone_number_id')
                ->label(__('ui_9bac39e4c734'))
                ->helperText(__('ui_02c16dd86fb7'))
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->required(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->maxLength(255),

            TextInput::make('meta_waba_id')
                ->label(__('ui_3f02f5154a53'))
                ->helperText(__('ui_a3971b90ed3b'))
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->required(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->maxLength(255),

            TextInput::make('meta_token')
                ->label(__('ui_bc3c48298ca9'))
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                // Em branco na edicao NAO apaga o que ja existe. Apagar a credencial de um
                // cliente por descuido derrubaria o atendimento dele, e o sintoma seria
                // "parou de enviar" — sem ninguem ligar a causa a esta tela.
                ->dehydrated(fn ($state) => filled($state))
                // Nunca devolver o token ao navegador ao abrir a tela: ele estaria no HTML
                // da pagina, no cache do navegador e em qualquer print de tela.
                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                ->helperText(__('ui_116085597df5'))
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->columnSpanFull(),

            TextInput::make('telefone_e164')
                ->label(__('ui_6d069f697319'))
                ->helperText(__('ui_39344cd5ca81'))
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->maxLength(255),

            // --------------------------------------------------------------- site
            // A chave (site_key) nao aparece aqui para editar: ela nasce sozinha
            // (Channel::booted()) e quem precisa dela e o <script> do site, mostrado
            // pela acao "Ver codigo de instalacao" — nao um campo que da para apagar
            // por descuido e quebrar o balao de todo mundo que ja colou o codigo.
            TextInput::make('site_dominio')
                ->label(__('ui_6ce95fb1db07'))
                ->helperText(__('ui_0b860b2b8b64'))
                ->visible(fn (Get $get) => $get('tipo') === Channel::SITE)
                ->maxLength(255),

            TextInput::make('site_saudacao')
                ->label(__('ui_5f1a299e916b'))
                ->helperText(__('ui_66855d1eb9a5'))
                ->visible(fn (Get $get) => $get('tipo') === Channel::SITE)
                ->maxLength(500)
                ->columnSpanFull(),

            // ----------------------------------------------------- messenger/instagram
            // Somente leitura, sem formulario manual alternativo: ao contrario do
            // oficial, aqui nao existe "Phone Number ID" para copiar do painel da Meta
            // — o login pelo Facebook e o unico caminho, entao nao ha o que preencher
            // a mao. Ver ConectarMetaSocial/EditChannel::reconectar_meta_social.
            Placeholder::make('meta_page_nome')
                ->label(__('ui_03a3f3b8f12e'))
                ->content(fn ($record) => $record?->meta_page_nome ?: '—')
                ->visible(fn (Get $get) => in_array($get('tipo'), [Channel::MESSENGER, Channel::INSTAGRAM], true)),

            Placeholder::make('meta_ig_username')
                ->label(__('ui_ef8ed44f8ab2'))
                ->content(fn ($record) => $record?->meta_ig_username ? '@'.$record->meta_ig_username : '—')
                ->visible(fn (Get $get) => $get('tipo') === Channel::INSTAGRAM),

            // ---------------------------------------------------------- evolution
            // O risco e dito ANTES de conectar, na hora da escolha: descoberto depois, ja com o
            // numero banido, vira reclamacao contra o produto.
            Placeholder::make('risco_evolution')
                ->hiddenLabel()
                ->content(new \Illuminate\Support\HtmlString('<div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">'
                    .'<strong>WhatsApp por QR code não é a API oficial.</strong> Não tem custo por mensagem, mas o número pode ser desconectado '
                    .'ou banido pelo WhatsApp, principalmente com envio em massa. Para operação crítica, use o canal oficial da Meta.</div>'))
                ->visible(fn (Get $get) => $get('tipo') === Channel::EVOLUTION),

            // Derivados: gerados ao salvar ou vindos do proprio WhatsApp.
            Placeholder::make('instance_name')
                ->label(__('ui_c67704c7f942'))
                ->content(fn ($record) => $record?->instance_name ?? 'gerada ao salvar')
                ->visible(fn (Get $get, $record) => $record !== null && $get('tipo') === Channel::EVOLUTION),

            Placeholder::make('telefone_placeholder')
                ->label(__('ui_6d069f697319'))
                ->content(fn ($record) => $record?->telefone_e164 ?? 'aparece apos conectar')
                ->visible(fn (Get $get, $record) => $record !== null && $get('tipo') === Channel::EVOLUTION),

            // --------------------------------------------------------------- ambos
            Placeholder::make('status')
                ->label(__('ui_deed29689ce7'))
                ->content(fn ($record) => match ($record?->status) {
                    'open'       => 'conectado',
                    'connecting' => 'conectando',
                    null         => '—',
                    default      => $record->status,
                })
                ->visibleOn('edit'),

            Placeholder::make('ultimo_erro')
                ->label(__('ui_2dc3d72fb401'))
                ->content(fn ($record) => $record?->ultimo_erro ?: 'nenhum')
                ->visibleOn('edit')
                ->columnSpanFull(),
        ]);
    }
}
