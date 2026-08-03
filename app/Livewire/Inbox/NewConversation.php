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
    private const MIN_BUSCA = 2;

    private const LIMITE = 8;

    public bool $aberto = false;

    // Um campo so: pode ser nome de contato salvo ou telefone novo.
    public string $termo = '';

    public string $primeiraMensagem = '';

    public function alternar(): void
    {
        $this->aberto = ! $this->aberto;
        $this->resetErrorBag();

        if (! $this->aberto) {
            $this->reset(['termo', 'primeiraMensagem']);
        }
    }

    // Contato salvo ja tem JID conhecido: perguntar de novo ao WhatsApp seria
    // round-trip inutil e deixaria a tela esperando por nada.
    public function selecionarContato(int $contactId): void
    {
        $this->resetErrorBag();

        // find sob o escopo global: contato de outro tenant nao existe aqui
        $contato = Contact::find($contactId);

        if (! $contato) {
            $this->addError('termo', 'Contato nao encontrado.');

            return;
        }

        $canal = $this->canalConectado();

        if (! $canal) {
            return;
        }

        $this->abrir($canal, $contato);
    }

    public function iniciar(): void
    {
        $this->resetErrorBag();

        $e164 = PhoneNumber::toE164($this->termo);

        if (! $e164) {
            $this->addError('termo', 'Nao achei contato com esse nome. Para um numero novo, informe DDD + numero.');

            return;
        }

        $canal = $this->canalConectado();

        if (! $canal) {
            return;
        }

        // Perguntar ao WhatsApp antes de criar evita contato fantasma no banco e
        // disparo para numero inexistente, que e gatilho de banimento.
        try {
            $resposta = app(EvolutionService::class)
                ->checkNumbers($canal->instance_name, [ltrim($e164, '+')]);
        } catch (\Throwable $e) {
            $this->addError('termo', 'Nao consegui verificar o numero no WhatsApp. Tente de novo.');

            return;
        }

        $info = collect($resposta)->first();

        if (! is_array($info) || ! ($info['exists'] ?? false)) {
            $this->addError('termo', 'Esse numero nao tem WhatsApp.');

            return;
        }

        $canonico = PhoneNumber::toE164($info['jid'] ?? $info['number'] ?? null) ?: $e164;

        $contato = Contact::firstOrCreate(
            ['jid' => Contact::jidDoTelefone($canonico)],
            ['tipo' => Contact::PESSOA, 'telefone_e164' => $canonico],
        );

        $this->abrir($canal, $contato);
    }

    private function canalConectado(): ?Channel
    {
        $canal = Channel::where('status', 'open')->orderBy('id')->first();

        if (! $canal) {
            $this->addError('termo', 'Nenhum canal conectado. Conecte um numero no painel antes.');

            return null;
        }

        return $canal;
    }

    private function abrir(Channel $canal, Contact $contato): void
    {
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

        $this->reset(['termo', 'primeiraMensagem']);
        $this->aberto = false;

        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function render()
    {
        $termo = trim($this->termo);
        $contatos = collect();
        $emAtendimento = [];

        // Nao consulta com o painel fechado: seria query a cada re-render da
        // lista de conversas, que acontece a cada mensagem que chega.
        if ($this->aberto && mb_strlen($termo) >= self::MIN_BUSCA) {
            // so digitos do termo, para casar telefone digitado com mascara
            $digitos = preg_replace('/\D+/', '', $termo) ?? '';

            $contatos = Contact::query()
                ->where(function ($q) use ($termo, $digitos) {
                    $q->where('nome', 'ilike', '%'.$termo.'%');

                    if ($digitos !== '') {
                        $q->orWhere('telefone_e164', 'ilike', '%'.$digitos.'%')
                            ->orWhere('jid', 'ilike', '%'.$digitos.'%');
                    }
                })
                ->orderByRaw('nome is null')
                ->orderBy('nome')
                ->limit(self::LIMITE)
                ->get();

            if ($contatos->isNotEmpty()) {
                // quem ja esta sendo atendido, para o atendente nao atropelar colega
                $emAtendimento = Conversation::with('atendente')
                    ->whereIn('contact_id', $contatos->pluck('id'))
                    ->where('status', '!=', Conversation::ARQUIVADA)
                    ->get()
                    ->keyBy('contact_id')
                    ->map(fn ($c) => [
                        'status'    => $c->rotuloStatus(),
                        'atendente' => $c->atendente?->name,
                    ])
                    ->all();
            }
        }

        return view('livewire.inbox.new-conversation', [
            'contatos'         => $contatos,
            'emAtendimento'    => $emAtendimento,
            'telefoneDigitado' => PhoneNumber::toE164($termo),
        ]);
    }
}
