<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\EvolutionService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A tela do QR Code, com endereco proprio.
 *
 * ANTES ELA ERA UM MODAL escondido atras de uma acao na lista: criar o canal levava de volta a
 * tabela, e a pessoa tinha de descobrir sozinha qual botao abria o codigo. Tres telas para
 * fazer a unica coisa que ela veio fazer.
 *
 * Com endereco proprio, o cadastro leva direto para ca, o link pode ser mandado para o cliente
 * conectar sozinho, e recarregar a pagina nao perde o lugar.
 */
class ConectarChannel extends Page
{
    // Sem este trait o Filament nao sabe que esta pagina fala de UM registro: o getUrl()
    // serializa o modelo inteiro dentro da URL, e o resolveRecord — que e quem aplica o
    // escopo da conta — nunca roda. Ou seja, faltava o trait E a defesa entre contas.
    use InteractsWithRecord;

    protected static string $resource = ChannelResource::class;

    protected string $view = 'filament.resources.channels.pages.conectar';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->nome = (string) $this->record->nome;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Conectar '.$this->record->nome;
    }

    /** Nome novo, digitado na propria tela do QR. */
    public string $nome = '';

    public function salvarNome(): void
    {
        $nome = trim($this->nome);

        if ($nome === '') {
            return;
        }

        $this->record->update(['nome' => mb_substr($nome, 0, 60)]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recriar')
                ->label('Recomeçar a conexão')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                // O caso real: a sessao ficou num estado ruim e o QR nao aparece mais. Recriar
                // a instancia resolve, e sem este botao a saida seria apagar o canal — que
                // levaria o chatbot junto, como ja aconteceu aqui.
                ->requiresConfirmation()
                ->modalHeading('Recomeçar do zero?')
                ->modalDescription('Descarta a sessão atual e gera um QR Code novo. O histórico de conversas não é afetado.')
                ->action(function () {
                    try {
                        $evolution = app(EvolutionService::class);
                        $evolution->deleteInstance($this->record->instance_name);
                        $evolution->createInstance($this->record->instance_name, $this->record->webhookUrl());

                        $this->record->update(['status' => 'close', 'ultimo_erro' => null]);
                    } catch (\Throwable $e) {
                        $this->record->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
                    }
                }),
        ];
    }
}
