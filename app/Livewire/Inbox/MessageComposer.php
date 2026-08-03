<?php

namespace App\Livewire\Inbox;

use App\Jobs\SendMediaMessage;
use App\Jobs\SendTextMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MediaService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageComposer extends Component
{
    use WithFileUploads;

    public ?int $conversationId = null;

    public string $corpo = '';

    public $anexo = null;

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
    }

    public function getListeners(): array
    {
        return ['abrir-conversa' => 'abrir'];
    }

    public function abrir(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->reset(['corpo', 'anexo']);
    }

    public function updatedAnexo(): void
    {
        $this->validate(['anexo' => 'nullable|file|max:32768']);
    }

    public function removerAnexo(): void
    {
        $this->reset('anexo');
        $this->resetErrorBag('anexo');
    }

    public function enviar(): void
    {
        $this->resetErrorBag();

        if (! $this->anexo && trim($this->corpo) === '') {
            $this->addError('corpo', 'Escreva algo ou anexe um arquivo.');

            return;
        }

        $conversa = Conversation::findOrFail($this->conversationId);

        $mensagem = $this->anexo
            ? $this->comAnexo($conversa)
            : $this->somenteTexto($conversa);

        if (! $mensagem) {
            return;
        }

        $conversa->update(['ultima_msg_em' => now()]);

        $this->reset(['corpo', 'anexo']);
        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    private function somenteTexto(Conversation $conversa): ?Message
    {
        $this->validate(['corpo' => 'required|string|max:4000']);

        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => 'text',
            'corpo'           => $this->corpo,
            'status'          => Message::STATUS_QUEUED,
        ]);

        SendTextMessage::dispatch($mensagem->id);

        return $mensagem;
    }

    private function comAnexo(Conversation $conversa): ?Message
    {
        $media = app(MediaService::class);

        try {
            $meta = $media->guardarUpload($conversa, $this->anexo);
        } catch (\Throwable $e) {
            $this->addError('anexo', $e->getMessage());

            return null;
        }

        // Gravacao do navegador vem em webm; sem converter para OGG/Opus o
        // WhatsApp mostra como arquivo anexado em vez de nota de voz.
        if ($meta['tipo'] === 'audio') {
            $convertido = $media->converterParaVoz($meta['path']);

            if ($convertido !== $meta['path']) {
                $meta['path'] = $convertido;
                $meta['mime'] = 'audio/ogg';
                $meta['tamanho'] = Storage::disk('local')->size($convertido);
            }
        }

        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => $meta['tipo'],
            'legenda'         => trim($this->corpo) !== '' ? trim($this->corpo) : null,
            'media_path'      => $meta['path'],
            'media_mime'      => $meta['mime'],
            'media_nome'      => $meta['nome'],
            'media_tamanho'   => $meta['tamanho'],
            'status'          => Message::STATUS_QUEUED,
        ]);

        SendMediaMessage::dispatch($mensagem->id);

        return $mensagem;
    }

    public function render()
    {
        return view('livewire.inbox.message-composer');
    }
}
