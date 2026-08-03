<?php

namespace App\Livewire\Inbox;

use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

// Separado da janela de proposito: mensagem em conversa fechada so precisa
// atualizar esta lista. Um componente unico re-renderizaria a tela toda a cada
// mensagem que chega.
class ConversationList extends Component
{
    // Os cinco baldes PARTICIONAM as conversas: sem lacuna e sem sobreposicao.
    // Se algo escapasse de todos, alguem perderia atendimento sem nunca saber.
    public const BALDES = [
        'novos'      => 'Novos',
        'meus'       => 'Meus',
        'outros'     => 'Outros',
        'grupos'     => 'Grupos',
        'arquivadas' => 'Arquivadas',
    ];

    public const ORDENS = [
        'recentes' => 'Últimas interações primeiro',
        'antigos'  => 'Mais antigos primeiro',
    ];

    public string $balde = 'novos';

    public bool $somenteNaoLidas = false;

    public string $busca = '';

    // null = padrao do balde. A escolha no menu sobrepoe e continua valendo.
    public ?string $ordem = null;

    public ?int $selecionada = null;

    public function getListeners(): array
    {
        $listeners = [
            'abrir-conversa'      => 'marcarSelecionada',
            'conversa-atualizada' => '$refresh',
        ];

        if ($tenantId = auth()->user()?->tenant_id) {
            $listeners['echo-private:tenant.'.$tenantId.'.conversations,.message.stored'] = '$refresh';
        }

        return $listeners;
    }

    public function selecionarBalde(string $balde): void
    {
        if (array_key_exists($balde, self::BALDES)) {
            $this->balde = $balde;
        }
    }

    public function selecionarOrdem(string $ordem): void
    {
        if (array_key_exists($ordem, self::ORDENS)) {
            $this->ordem = $ordem;
        }
    }

    // Fila se atende por ordem de chegada: em Novos, quem espera mais aparece
    // primeiro. Nos outros baldes o que importa e a conversa que se moveu agora.
    public function ordemEfetiva(): string
    {
        return $this->ordem ?? ($this->balde === 'novos' ? 'antigos' : 'recentes');
    }

    public function selecionar(int $id): void
    {
        // findOrFail sob o escopo global: conversa de outro tenant nao existe aqui
        $conversa = Conversation::findOrFail($id);

        $this->selecionada = $conversa->id;
        $conversa->update(['nao_lidas' => 0]);

        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function marcarSelecionada(int $conversationId): void
    {
        $this->selecionada = $conversationId;
    }

    private function doBalde(string $balde): Builder
    {
        $eu = auth()->id();

        // Grupo fica FORA da fila de atendimento: num provedor e bairro, tecnicos
        // e revenda — volume alto e quase nada exige atendimento individual. Em
        // Novos, 30 mensagens de grupo enterrariam quem pediu segunda via.
        $semGrupo = fn (Builder $q) => $q->whereHas('contact', fn ($c) => $c->where('tipo', '!=', Contact::GRUPO));

        return match ($balde) {
            'novos' => $semGrupo(Conversation::where('status', Conversation::NOVA)),

            'meus' => $semGrupo(
                Conversation::where('status', Conversation::EM_ATENDIMENTO)->where('atendente_id', $eu)
            ),

            // "ou atendente nulo" fecha o furo: conversa conduzida por automacao
            // tem status em atendimento sem humano e desapareceria da tela.
            'outros' => $semGrupo(
                Conversation::where('status', Conversation::EM_ATENDIMENTO)
                    ->where(fn ($q) => $q->whereNull('atendente_id')->orWhere('atendente_id', '!=', $eu))
            ),

            'grupos' => Conversation::where('status', '!=', Conversation::ARQUIVADA)
                ->whereHas('contact', fn ($c) => $c->where('tipo', Contact::GRUPO)),

            default => Conversation::where('status', Conversation::ARQUIVADA),
        };
    }

    private function aplicarRecortes(Builder $query): Builder
    {
        if ($this->somenteNaoLidas) {
            $query->where('nao_lidas', '>', 0);
        }

        $termo = trim($this->busca);

        if ($termo === '') {
            return $query;
        }

        $digitos = preg_replace('/\D+/', '', $termo) ?? '';

        return $query->where(function (Builder $q) use ($termo, $digitos) {
            $q->whereHas('contact', function ($c) use ($termo, $digitos) {
                $c->where('nome', 'ilike', '%'.$termo.'%');

                if ($digitos !== '') {
                    $c->orWhere('telefone_e164', 'ilike', '%'.$digitos.'%');
                }
            })->orWhereHas('messages', function ($m) use ($termo) {
                // transcricao entra na busca: sem ela, audio e buraco negro no
                // historico — "o cliente falou de cancelamento" nao encontra nada
                $m->where('corpo', 'ilike', '%'.$termo.'%')
                    ->orWhere('legenda', 'ilike', '%'.$termo.'%')
                    ->orWhere('transcricao', 'ilike', '%'.$termo.'%');
            });
        });
    }

    public function render()
    {
        // Badge de Novos conta tudo (toda conversa ali esta pendente). Nos outros
        // conta so nao lidas — assim todo badge significa a mesma coisa:
        // precisa dos seus olhos.
        $badges = [
            'novos'      => $this->doBalde('novos')->count(),
            'meus'       => $this->doBalde('meus')->where('nao_lidas', '>', 0)->count(),
            'outros'     => $this->doBalde('outros')->where('nao_lidas', '>', 0)->count(),
            'grupos'     => $this->doBalde('grupos')->where('nao_lidas', '>', 0)->count(),
            'arquivadas' => null,
        ];

        $conversas = $this->aplicarRecortes(
            $this->doBalde($this->balde)
                ->with(['contact', 'ultimaMensagem', 'atendente'])
                ->withCount('messages')
        )
            ->orderBy('ultima_msg_em', $this->ordemEfetiva() === 'antigos' ? 'asc' : 'desc')
            ->limit(50)
            ->get();

        return view('livewire.inbox.conversation-list', [
            'conversas'     => $conversas,
            'badges'        => $badges,
            'baldes'        => self::BALDES,
            'ordens'        => self::ORDENS,
            'ordemEfetiva'  => $this->ordemEfetiva(),
        ]);
    }
}
