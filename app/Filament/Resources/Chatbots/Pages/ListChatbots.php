<?php

namespace App\Filament\Resources\Chatbots\Pages;

use App\Filament\Resources\Chatbots\ChatbotResource;
use App\Models\Chatbot;
use App\Services\ChatbotFluxo;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListChatbots extends ListRecords
{
    protected static string $resource = ChatbotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Comecar de uma arvore vazia e a parte mais dificil de configurar um
            // bot. Comecar de uma plausivel e trocar textos.
            Action::make('exemplo')
                ->label('Criar fluxo de exemplo')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Cria um fluxo de provedor já montado (Financeiro, Suporte com submenu, Horário), desativado, para você editar.')
                ->action(function () {
                    // O exemplo tem que nascer no formato que o motor percorre.
                    // Antes montava a arvore antiga, que virou codigo morto.
                    $bot = Chatbot::create([
                        'nome'                  => 'Recepção',
                        'ativo'                 => false,
                        'mensagem_nao_entendi'  => 'Não entendi. Escolha uma das opções:',
                        'mensagem_transferindo' => 'Um momento, já vou te encaminhar.',
                        'mensagem_boas_vindas'  => 'Olá!',
                    ]);

                    app(ChatbotFluxo::class)->criarExemplo($bot);

                    Notification::make()
                        ->success()
                        ->title('Fluxo de exemplo criado')
                        ->body('Está em rascunho e desativado. Ajuste os textos, ligue as pontas e publique.')
                        ->send();

                    return redirect(ChatbotResource::getUrl('fluxo', ['record' => $bot]));
                }),
        ];
    }
}
