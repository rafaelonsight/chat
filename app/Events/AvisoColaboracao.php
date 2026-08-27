<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class AvisoColaboracao implements ShouldBroadcast
{
    public function __construct(public int $userId, public string $id, public string $titulo, public string $url) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('recados.'.$this->userId)];
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->id, 'titulo' => $this->titulo, 'url' => $this->url];
    }
}
