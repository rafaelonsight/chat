<?php

namespace App\Events;

use App\Models\DirectMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Um recado chegou para alguem.
 *
 * VAI SO PARA O DESTINATARIO. O canal e o dele, e nao um canal da equipe: recado direto que
 * trafega num canal coletivo e recado que qualquer um com o console aberto le.
 */
class RecadoDireto implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DirectMessage $recado) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('recados.'.$this->recado->para_user_id)];
    }

    /**
     * O CORPO NAO VAI NO AVISO, so o remetente.
     *
     * O aviso serve para a tela saber que tem coisa nova e ir buscar. Mandar o texto aqui o
     * espalharia por um caminho a mais, e a tela ja vai ao servidor de qualquer jeito para
     * marcar como lido.
     */
    public function broadcastWith(): array
    {
        return ['de' => $this->recado->de_user_id];
    }
}
