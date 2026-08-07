<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\EvolutionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;

class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    /**
     * UM botao, e dentro dele as opcoes que existem hoje.
     *
     * Dois botoes lado a lado davam o mesmo peso a dois caminhos que nao tem o mesmo peso — e
     * no dia em que Instagram e Messenger entrarem, seriam quatro botoes disputando o
     * cabecalho. Um menu cresce; uma fileira de botoes, nao.
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('evolution')
                    ->label('WhatsApp por QR Code')
                    ->icon('heroicon-o-qr-code')
                    // Sem formulario: cria e leva direto ao codigo. Ver abaixo o porque.
                    ->action(fn () => $this->criarPorQrCode()),

                Action::make('oficial')
                    ->label('WhatsApp oficial (Meta)')
                    ->icon('heroicon-o-check-badge')
                    // Este continua com formulario porque ele PRECISA de dados que so a pessoa
                    // tem: Phone Number ID, WABA e token. Nao ha o que adivinhar.
                    ->url(static::getResource()::getUrl('create').'?tipo='.Channel::META_CLOUD),
            ])
                ->label('Novo canal')
                ->icon('heroicon-o-plus')
                ->button(),
        ];
    }

    /**
     * Cria o canal e vai direto para o QR Code.
     *
     * O FORMULARIO SAIU DO CAMINHO, e essa e a mudanca.
     *
     * Pedir "nome do canal" antes do QR e pedir uma decisao que a pessoa ainda nao tem como
     * tomar: ela veio conectar um numero. O nome bom para o canal so aparece DEPOIS de
     * conectar — normalmente e o proprio numero — e o sistema passa a preenche-lo sozinho.
     *
     * Ate la o canal nasce com um nome provisorio, e a tela do QR tem um campo para trocar.
     */
    private function criarPorQrCode()
    {
        $canal = Channel::create([
            'tenant_id' => auth()->user()->tenant_id,
            'tipo'      => Channel::EVOLUTION,
            'nome'      => Channel::nomeProvisorio(),
        ])->refresh();

        // Falha aqui NAO impede o canal de existir: fica registrada, e a tela do QR mostra o
        // erro com o botao de recomecar. Sumir com o canal deixaria a pessoa sem nada na tela
        // e sem saber o que aconteceu.
        try {
            app(EvolutionService::class)->createInstance($canal->instance_name, $canal->webhookUrl());
        } catch (\Throwable $e) {
            $canal->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return redirect(static::getResource()::getUrl('conectar', ['record' => $canal]));
    }
}
