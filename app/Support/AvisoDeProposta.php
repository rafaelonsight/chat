<?php

namespace App\Support;

use App\Models\Proposal;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Sua proposta foi aceita."
 *
 * O AVISO E O PONTO DA COISA. Proposta aceita sem ninguem saber e proposta que espera dois dias
 * pelo primeiro passo da entrega — e o cliente, do outro lado, achando que ninguem viu.
 *
 * Vai para quem CRIOU a proposta e para os administradores da conta: quem vendeu precisa agir, e
 * quem manda precisa saber que entrou.
 */
class AvisoDeProposta
{
    public static function aceita(Proposal $p): void
    {
        self::avisar(
            $p,
            $p->cliente_nome.' aceitou a proposta '.$p->numero,
            trim(($p->aceita_por ? 'Confirmado por '.$p->aceita_por.'. ' : '').self::valores($p)),
            'success',
            'heroicon-o-check-badge',
        );
    }

    public static function recusada(Proposal $p): void
    {
        self::avisar(
            $p,
            $p->cliente_nome.' recusou a proposta '.$p->numero,
            $p->recusa_motivo ?: 'Sem motivo informado.',
            'danger',
            'heroicon-o-x-circle',
        );
    }

    private static function valores(Proposal $p): string
    {
        $partes = [];

        if ((float) $p->total_unico > 0) {
            $partes[] = 'R$ '.number_format((float) $p->total_unico, 2, ',', '.');
        }

        if ((float) $p->total_recorrente > 0) {
            $partes[] = 'R$ '.number_format((float) $p->total_recorrente, 2, ',', '.').'/mês';
        }

        return implode(' + ', $partes);
    }

    private static function avisar(Proposal $p, string $titulo, string $corpo, string $cor, string $icone): void
    {
        $destinos = User::withoutGlobalScope('tenant')
            ->where('tenant_id', $p->tenant_id)
            ->where(fn ($q) => $q->where('admin', true)->orWhere('id', $p->criada_por))
            ->get();

        foreach ($destinos as $pessoa) {
            Notification::make()
                ->title($titulo)
                ->body($corpo)
                ->icon($icone)
                ->color($cor)
                ->actions([
                    Action::make('abrir')
                        ->label('Ver a proposta')
                        ->url(route('proposta', $p->token))
                        ->markAsRead(),
                ])
                ->sendToDatabase($pessoa);
        }
    }
}
