<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "Fulano te chamou numa nota."
 *
 * SO O AVISO, e nao a mencao em si: quem registra o texto e a nota. Aqui e o empurrao — e ele
 * existe porque nota sem aviso e diario, nao conversa.
 *
 * VAI PARA O BANCO E NAO POR E-MAIL. O aviso serve para agir agora, dentro do painel; e-mail
 * para cada mencao viraria ruido que se aprende a arquivar sem ler, e ai o aviso morre. Se um
 * dia fizer falta para quem esta fora, o e-mail entra como escolha da pessoa, nao como padrao.
 */
class AvisoDeMencao
{
    /**
     * De quem e a conversa, para caber na frase do aviso.
     *
     * Tres degraus, e o do meio e o que importa: contato sem nome cadastrado e o caso COMUM —
     * numero novo que acabou de escrever. Cair direto no generico dava "Rafael te chamou em a
     * conversa", que alem de torto nao dizia de quem se tratava, e obrigava a abrir para saber
     * se era urgente. O telefone ja responde isso.
     */
    private static function quemE(Conversation $conversa): string
    {
        if ($nome = trim((string) $conversa->contact?->nome)) {
            return $nome;
        }

        if ($fone = $conversa->contact?->telefone_e164) {
            return \App\Support\PhoneNumber::discavel($fone);
        }

        return 'uma conversa';
    }

    /** @param iterable<int, User> $paraQuem */
    public static function enviar(User $quemChamou, Conversation $conversa, string $texto, iterable $paraQuem): int
    {
        $enviados = 0;
        $nome = self::quemE($conversa);

        foreach ($paraQuem as $pessoa) {
            // Chamar a si mesmo nao avisa nada: a pessoa acabou de escrever.
            if ($pessoa->id === $quemChamou->id) {
                continue;
            }

            Notification::make()
                ->title($quemChamou->primeiroNome().' te chamou em '.$nome)
                // O TEXTO DA NOTA VAI NO AVISO. Sem ele, o aviso obriga a abrir para descobrir
                // se e urgente — e quem esta atendendo nao abre, deixa para depois.
                ->body(Str::limit(trim($texto), 140))
                ->icon('heroicon-o-at-symbol')
                ->iconColor('warning')
                ->actions([
                    Action::make('abrir')
                        ->label('Abrir conversa')
                        ->url(route('filament.admin.pages.atendimento', ['conversa' => $conversa->id]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($pessoa);

            $enviados++;
        }

        return $enviados;
    }
}
