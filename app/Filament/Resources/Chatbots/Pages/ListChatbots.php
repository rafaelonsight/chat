<?php

namespace App\Filament\Resources\Chatbots\Pages;

use App\Filament\Resources\Chatbots\ChatbotResource;
use App\Models\Chatbot;
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
                    $bot = Chatbot::criarExemplo();

                    Notification::make()
                        ->success()
                        ->title('Fluxo de exemplo criado')
                        ->body('Está desativado. Edite os textos e ative quando quiser usar.')
                        ->send();

                    return redirect(ChatbotResource::getUrl('edit', ['record' => $bot]));
                }),
        ];
    }
}
