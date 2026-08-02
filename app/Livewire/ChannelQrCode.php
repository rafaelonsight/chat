<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Services\EvolutionService;
use Livewire\Component;

class ChannelQrCode extends Component
{
    public Channel $channel;

    public ?string $qrBase64 = null;

    public string $estado = 'desconhecido';

    public function mount(Channel $channel): void
    {
        $this->channel = $channel;
        $this->atualizar();
    }

    // Chamado por wire:poll enquanto o numero nao conecta. O QR da Evolution
    // expira em segundos, entao precisa ser reemitido a cada ciclo.
    public function atualizar(): void
    {
        $evolution = app(EvolutionService::class);

        try {
            $estado = $evolution->connectionState($this->channel->instance_name);
            $this->estado = data_get($estado, 'instance.state', 'desconhecido');

            if ($this->estado === 'open') {
                $this->qrBase64 = null;
                $this->channel->forceFill(['status' => 'open', 'conectado_em' => now()])->saveQuietly();

                return;
            }

            $this->qrBase64 = data_get($evolution->connect($this->channel->instance_name), 'base64');
        } catch (\Throwable $e) {
            $this->estado = 'erro';
            $this->channel->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    public function render()
    {
        return view('livewire.channel-qr-code');
    }
}
