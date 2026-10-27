<?php

namespace App\Filament\Resources\MetaTemplates\Pages;

use App\Filament\Resources\MetaTemplates\MetaTemplateResource;
use App\Models\Channel;
use App\Services\Canais\SincronizarTemplatesMeta;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMetaTemplates extends ListRecords
{
    protected static string $resource = MetaTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('precosConsumo')->label(__('Preços e consumo'))->icon('heroicon-o-currency-pound')->color('gray')->url(\App\Filament\Pages\ConsumoConversas::getUrl()),
            \Filament\Actions\CreateAction::make()->label(__('Novo template')),
            Action::make('sincronizar')
                ->label(__('ui_41e73b85660d'))
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $canais = Channel::where('tipo', Channel::META_CLOUD)
                        ->whereNotNull('meta_waba_id')
                        ->get()->unique('meta_waba_id');

                    if ($canais->isEmpty()) {
                        Notification::make()
                            ->warning()
                            ->title(__('ui_7c40ae7d29ed'))
                            ->body(__('ui_fcf057ac6558'))
                            ->persistent()
                            ->send();

                        return;
                    }

                    $sincronizador = app(SincronizarTemplatesMeta::class);
                    $linhas = [];
                    $houveFalha = false;

                    // Um canal com problema NAO interrompe os outros: com varios clientes,
                    // a credencial de um vencida nao pode deixar todo mundo sem templates.
                    foreach ($canais as $canal) {
                        $r = $sincronizador->paraCanal($canal);

                        if (! $r['ok']) {
                            $houveFalha = true;
                            $linhas[] = $canal->nome.': '.$r['erro'];

                            continue;
                        }

                        $linhas[] = $canal->nome.': '.$r['total'].' template(s)'
                            .($r['novos'] ? ', '.$r['novos'].' novo(s)' : '')
                            .($r['nao_suportados'] ? ', '.$r['nao_suportados'].' sem envio pelo '.config('app.name') : '')
                            .($r['apagados'] ? ', '.$r['apagados'].' removido(s)' : '');
                    }

                    Notification::make()
                        ->status($houveFalha ? 'warning' : 'success')
                        ->title($houveFalha ? 'Sincronizado com falhas' : 'Templates sincronizados')
                        ->body(implode(' · ', $linhas))
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
