<?php

namespace App\Livewire\Inbox;

use App\Jobs\SendTextMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\EvolutionService;
use App\Support\PhoneNumber;
use Livewire\Component;

class NewConversation extends Component
{
    public bool $aberto = false;

    public string $numero = '';

    public string $primeiraMensagem = '';

    public function alternar(): void
    {
        $this->aberto = ! $this->aberto;
        $this->resetErrorBag();
    }

    public function iniciar(): void
    {
        $this->resetErrorBag();

        $e164 = PhoneNumber::toE164($this->numero);

        if (! $e164) {
            $this->addError('numero', 'Numero invalido. Informe DDD + numero, ex.: (84) 99614-3373.');

            return;
        }

        $canal = Channel::where('status', 'open')->orderBy('id')->first();

        if (! $canal) {
            $this->addError('numero', 'Nenhum canal conectado. Conecte um numero no painel antes.');

            return;
        }

        // Perguntar ao WhatsApp antes de criar qualquer coisa evita dois
        // problemas: contato fantasma no banco e disparo para numero
        // inexistente, que e gatilho de banimento.
        try {
            $resposta = app(EvolutionService::class)
                ->checkNumbers($canal->instance_name, [ltrim($e164, '+')]);
        } catch (\Throwable $e) {
            $this->addError('numero', 'Nao consegui verificar o numero no WhatsApp. Tente de novo.');

            return;
        }

        $info = collect($resposta)->first();

        if (! is_array($info) || ! ($info['exists'] ?? false)) {
            $this->addError('numero', 'Esse numero nao tem WhatsApp.');

            return;
        }

        $canonico = PhoneNumber::toE164($info['jid'] ?? $info['number'] ?? null) ?: $e164;

        $contato = Contact::firstOrCreate(
            ['jid' => Contact::jidDoTelefone($canonico)],
            ['tipo' => Contact::PESSOA, 'telefone_e164' => $canonico],
        );

        $conversa = Conversation::abertaOuNova($canal->id, $contato->id, $canal->tenant_id);

        // sobe para o topo da lista mesmo sem mensagem ainda
        $conversa->update(['ultima_msg_em' => now()]);

        if (trim($this->primeiraMensagem) !== '') {
            $mensagem = Message::create([
                'conversation_id' => $conversa->id,
                'channel_id'      => $canal->id,
                'direcao'         => 'out',
                'tipo'            => 'text',
                'corpo'           => trim($this->primeiraMensagem),
                'status'          => Message::STATUS_QUEUED,
            ]);

            SendTextMessage::dispatch($mensagem->id);
        }

        $this->reset(['numero', 'primeiraMensagem']);
        $this->aberto = false;

        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function render()
    {
        return view('livewire.inbox.new-conversation');
    }
}
