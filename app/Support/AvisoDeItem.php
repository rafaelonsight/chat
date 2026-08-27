<?php

namespace App\Support;

use App\Events\AvisoColaboracao;
use App\Models\{BoardItem, User};
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AvisoDeItem
{
    public static function enviar(BoardItem $item, array $ids, string $titulo): void
    {
        $tenant = $item->tenant_id;
        $autor = auth()->id();
        $url = '/admin/paineis?painel='.$item->funnel_id.'&item='.$item->id;
        $corpo = $item->titulo;
        DB::afterCommit(function () use ($tenant, $autor, $ids, $titulo, $url, $corpo) {
            foreach (User::withoutGlobalScope('tenant')->where('tenant_id', $tenant)
                ->whereIn('id', array_unique(array_filter($ids)))->where('id', '!=', $autor ?? 0)->get() as $user) {
                $id = (string) Str::uuid();
                Notification::make($id)->title(e($titulo))->body(e($corpo))->icon('heroicon-o-clipboard-document-check')
                    ->actions([Action::make('abrir')->label('Abrir tarefa')->url($url)->markAsRead()])
                    ->sendToDatabase($user);
                event(new AvisoColaboracao($user->id, $id, $titulo, $url));
            }
        });
    }
}
