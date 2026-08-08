<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Alguem bateu na porta de uma sala.
 *
 * O BURACO QUE ISTO FECHA: a fila da portaria so aparece DENTRO da sala. Quem manda o link e
 * volta para o atendimento nao ve nada, e o convidado fica esperando por alguem que nao sabe
 * que ele chegou. Numa reuniao marcada com hora, esse e o caso comum — o cliente clica no
 * horario, e quem convidou ainda esta em outra conversa.
 *
 * VAI PARA O CANAL DA CONTA INTEIRA, e nao so para quem criou a sala. Qualquer um da equipe
 * pode liberar, e o que nao pode acontecer e o convidado esperar porque justamente a pessoa
 * que o convidou saiu para o almoco.
 */
class AlguemEsperandoNaSala implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly string $titulo,
        public readonly string $url,
    ) {}

    public static function de(Meeting $reuniao, string $nome): self
    {
        return new self(
            $reuniao->tenant_id,
            $nome,
            $reuniao->titulo ?: 'Reunião',
            $reuniao->url(),
        );
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        // O mesmo canal das mensagens: a conta ja assina esse, e um canal a mais seria mais uma
        // assinatura para manter viva sem nada em troca.
        return [new PrivateChannel('tenant.'.$this->tenantId.'.conversations')];
    }

    public function broadcastAs(): string
    {
        return 'sala.esperando';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['nome' => $this->nome, 'titulo' => $this->titulo, 'url' => $this->url];
    }
}
