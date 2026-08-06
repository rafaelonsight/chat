<?php

namespace App\Livewire\Inbox;

use App\Jobs\SendTextMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Canais\Enviadores;
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

    // String e nao int: o <select> manda '' quando nada foi escolhido, e '' nao entra em
    // propriedade tipada como int.
    public string $canalId = '';

    public function alternar(): void
    {
        $this->aberto = ! $this->aberto;
        $this->resetErrorBag();

        if (! $this->aberto) {
            $this->reset(['termo', 'primeiraMensagem', 'canalId']);
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

        $canal = $this->canalEscolhido();

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

        $canal = $this->canalEscolhido();

        if (! $canal) {
            return;
        }

        // Perguntar ao WhatsApp antes de criar evita contato fantasma no banco e disparo
        // para numero inexistente, que e gatilho de banimento. Quem sabe perguntar e o
        // canal: a API oficial NAO tem essa consulta, e devolve "nao sei".
        try {
            $checagem = app(Enviadores::class)->para($canal)->verificarNumero($canal, $e164);
        } catch (\Throwable $e) {
            $this->addError('termo', 'Nao consegui verificar o numero no WhatsApp. Tente de novo.');

            return;
        }

        // Barra so quando o provedor disse NAO. "Nao sei" segue adiante: negar por uma
        // pergunta que ninguem respondeu seria inventar impedimento — e no canal oficial
        // TODA pergunta fica sem resposta, o que impediria qualquer conversa nova.
        if ($checagem['existe'] === false) {
            $this->addError('termo', 'Esse numero nao tem WhatsApp.');

            return;
        }

        $canonico = PhoneNumber::toE164($checagem['e164']) ?: $e164;

        // Pelas duas grafias: digitar o numero com o nono digito nao pode criar um segundo
        // contato de quem ja escreveu pelo canal oficial sem ele.
        $contato = Contact::acharOuCriarPorTelefone($canonico);

        $this->abrir($canal, $contato);
    }

    /**
     * Quais canais podem comecar uma conversa.
     *
     * NAO filtra so por status = open. Isso funcionava quando existia apenas a Evolution,
     * onde "conectado" e um estado real do aparelho. O canal oficial nao tem o que conectar:
     * ele depende de configuracao (Phone Number ID), e status open ali pode nunca chegar —
     * filtrar por status deixava o oficial invisivel para sempre, que era exatamente o
     * defeito: dava para responder pelo numero oficial, mas nunca comecar.
     */
    private function canaisDisponiveis()
    {
        return Channel::query()
            ->where(fn ($q) => $q
                ->where(fn ($evolution) => $evolution
                    ->where('tipo', Channel::EVOLUTION)
                    ->where('status', 'open'))
                ->orWhere(fn ($meta) => $meta
                    ->where('tipo', Channel::META_CLOUD)
                    ->whereNotNull('meta_phone_number_id')))
            ->orderBy('id')
            ->get();
    }

    private function canalEscolhido(): ?Channel
    {
        $disponiveis = $this->canaisDisponiveis();

        if ($disponiveis->isEmpty()) {
            $this->addError('termo', 'Nenhum canal disponivel. Conecte um numero ou cadastre o canal oficial antes.');

            return null;
        }

        if ($this->canalId !== '') {
            $canal = $disponiveis->firstWhere('id', (int) $this->canalId);

            if (! $canal) {
                $this->addError('canalId', 'Escolha um canal da lista.');

                return null;
            }

            return $canal;
        }

        if ($disponiveis->count() === 1) {
            return $disponiveis->first();
        }

        // Com mais de um canal, escolher pelo menor id sairia do numero errado sem avisar.
        // No canal oficial isso custa dinheiro e sai com a identidade errada para o cliente
        // final — nao e escolha que o sistema deva fazer no lugar de ninguem.
        $this->addError('canalId', 'Escolha por qual numero enviar.');

        return null;
    }

    private function abrir(Channel $canal, Contact $contato): void
    {
        // Fora da janela de 24h o canal oficial recusa texto livre. Barrar ANTES de criar
        // qualquer coisa: abrir a conversa e deixar a mensagem falhar daria bolha vermelha
        // de saida, e o atendente sem saber que o caminho era template.
        if (trim($this->primeiraMensagem) !== '' && $canal->exigeJanela()) {
            $aberta = Conversation::where('channel_id', $canal->id)
                ->where('contact_id', $contato->id)
                ->where('status', '!=', Conversation::ARQUIVADA)
                ->first();

            if (! $aberta || ! $aberta->podeEnviarLivre()) {
                $this->addError('primeiraMensagem', 'Neste numero a primeira mensagem precisa ser um template aprovado. Deixe em branco: eu abro a conversa e voce escolhe o template.');

                return;
            }
        }

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

                    // As duas grafias do trecho digitado: quem procura "98491-9939" tem de
                    // achar tambem o contato que chegou pela Meta como 554184919939.
                    foreach (PhoneNumber::variantesDeBusca($digitos) as $forma) {
                        $q->orWhere('telefone_e164', 'ilike', '%'.$forma.'%')
                            ->orWhere('jid', 'ilike', '%'.$forma.'%');
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
            // So com o painel aberto: com ele fechado seria uma query a cada mensagem que
            // chega, porque a lista de conversas re-renderiza junto.
            'canais'           => $this->aberto ? $this->canaisDisponiveis() : collect(),
            'contatos'         => $contatos,
            'emAtendimento'    => $emAtendimento,
            'telefoneDigitado' => PhoneNumber::toE164($termo),
        ]);
    }
}
