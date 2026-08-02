<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Services\EvolutionService;
use Filament\Resources\Pages\CreateRecord;

class CreateChannel extends CreateRecord
{
    protected static string $resource = ChannelResource::class;

    // Cria a instancia na Evolution logo apos salvar o canal. Falha aqui nao
    // impede o canal de existir: fica registrada e o botao Conectar tenta de novo.
    protected function afterCreate(): void
    {
        $canal = $this->record->refresh();

        try {
            app(EvolutionService::class)->createInstance($canal->instance_name, $canal->webhookUrl());
        } catch (\Throwable $e) {
            $canal->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
