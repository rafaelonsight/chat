<?php

namespace App\Filament\Resources\Chatbots\Pages;

use App\Filament\Resources\Chatbots\ChatbotResource;
use Filament\Resources\Pages\CreateRecord;

class CreateChatbot extends CreateRecord
{
    protected static string $resource = ChatbotResource::class;

    // Vai direto para a edicao: e lá que ficam as opcoes do menu, e um bot sem
    // opcoes nao faz nada.
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
