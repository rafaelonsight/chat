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
                ->label('Tipo de canal')
                ->options(Channel::TIPOS)
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
                ->helperText('Oficial: janela de 24h, modelo aprovado, sem grupo. Evolution: sem janela, com grupo.'),

            // O AVISO MAIS IMPORTANTE DESTE FORMULARIO, e ele nao existia.
            //
            // Vincular um numero a API oficial e IRREVERSIVEL na pratica: aquele numero para
            // de funcionar no aplicativo do WhatsApp, no celular, para sempre. Quem descobre
            // isso depois descobre da pior forma — tentando abrir o WhatsApp da empresa e nao
            // conseguindo mais.
            Placeholder::make('aviso_oficial')
                ->label('')
                ->content(new \Illuminate\Support\HtmlString(
                    '<div class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900 '
                    .'dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">'
                    .'<p class="font-semibold">Atenção: isto não tem volta fácil.</p>'
                    .'<p class="mt-1">Depois de vincular um número à API oficial da Meta, ele '
                    .'<strong>deixa de funcionar no aplicativo do WhatsApp</strong> — no celular, '
                    .'para sempre. Use um número que a empresa não usa no dia a dia.</p></div>'
                ))
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->columnSpanFull(),

            TextInput::make('nome')
                ->label('Nome do canal')
                ->helperText('Como voce identifica este numero. Ex.: Comercial, Cobranca.')
                ->required()
                ->maxLength(255),

            // ------------------------------------------------------------ oficial
            // Os identificadores ficam NO CANAL e nao na configuracao do servidor: com
            // eles globais, o segundo cliente nao caberia.
            TextInput::make('meta_phone_number_id')
                ->label('Phone Number ID')
                ->helperText('Painel da Meta: WhatsApp > Configuracao da API. E um numero longo — NAO e o telefone.')
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->required(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->maxLength(255),

            TextInput::make('meta_waba_id')
                ->label('WABA ID')
                ->helperText('Id da conta do WhatsApp Business. Necessario para modelos de mensagem.')
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->required(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->maxLength(255),

            TextInput::make('meta_token')
                ->label('Token de acesso deste numero')
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
                ->helperText('Em branco, usa a credencial do servidor. Preencha quando o numero for do cliente.')
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->columnSpanFull(),

            TextInput::make('telefone_e164')
                ->label('Numero')
                ->helperText('Como aparece para quem recebe. No oficial nao vem de QR Code: e informado.')
                ->visible(fn (Get $get) => $get('tipo') === Channel::META_CLOUD)
                ->maxLength(255),

            // ---------------------------------------------------------- evolution
            // Derivados: gerados ao salvar ou vindos do proprio WhatsApp.
            Placeholder::make('instance_name')
                ->label('Instancia na Evolution')
                ->content(fn ($record) => $record?->instance_name ?? 'gerada ao salvar')
                ->visible(fn (Get $get, $record) => $record !== null && $get('tipo') === Channel::EVOLUTION),

            Placeholder::make('telefone_placeholder')
                ->label('Numero')
                ->content(fn ($record) => $record?->telefone_e164 ?? 'aparece apos conectar')
                ->visible(fn (Get $get, $record) => $record !== null && $get('tipo') === Channel::EVOLUTION),

            // --------------------------------------------------------------- ambos
            Placeholder::make('status')
                ->label('Conexao')
                ->content(fn ($record) => match ($record?->status) {
                    'open'       => 'conectado',
                    'connecting' => 'conectando',
                    null         => '—',
                    default      => $record->status,
                })
                ->visibleOn('edit'),

            Placeholder::make('ultimo_erro')
                ->label('Ultimo erro')
                ->content(fn ($record) => $record?->ultimo_erro ?: 'nenhum')
                ->visibleOn('edit')
                ->columnSpanFull(),
        ]);
    }
}
