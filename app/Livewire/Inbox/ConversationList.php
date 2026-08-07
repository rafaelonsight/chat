<?php

namespace App\Livewire\Inbox;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\Team;
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

    // 'minhas' | 'todas' | 'sem' | id da equipe
    public string $equipe = 'minhas';

    // null = padrao do balde. A escolha no menu sobrepoe e continua valendo.
    public ?string $ordem = null;

    /**
     * Etiqueta do recorte. Texto porque vem do menu como texto, e '' num int
     * tipado explode antes de chegar aqui.
     */
    public ?string $etiqueta = null;

    /**
     * Canal do recorte. Texto pelo mesmo motivo da etiqueta: vem do menu como texto.
     *
     * Existe porque o Rafael tem tres canais e a lista nao distinguia: duas conversas com o
     * MESMO contato apareciam identicas, uma em cada numero. Nao era falta de informacao na
     * tela — era informacao que so existia no banco.
     */
    public ?string $canal = null;

    public ?int $selecionada = null;

    public const PAGINA = 30;

    // Antes havia limit(50) fixo: com 51 conversas a 51a simplesmente nao
    // aparecia e nada avisava. Nao era lentidao, era resultado errado em
    // silencio — o atendente nao tem como saber que existe fila escondida.
    public int $limite = self::PAGINA;

    public function getListeners(): array
    {
        $listeners = [
            'abrir-conversa'      => 'marcarSelecionada',
            // Depois de marcar como nao lida, nenhuma conversa fica selecionada: senao a
            // linha continuaria destacada como se estivesse aberta.
            'fechar-conversa'     => 'limparSelecao',
            'conversa-atualizada' => '$refresh',
        ];

        // Ponte propria, e nao o ouvinte "echo-private:..." do Livewire. Medido em
        // producao: o evento chegava no navegador e a ponte automatica do Livewire
        // nunca se registrava, entao a lista so mudava quando alguem clicava. O
        // resources/js/app.js escuta o canal e dispara este evento.
        $listeners['mensagem-chegou'] = '$refresh';

        return $listeners;
    }

    /**
     * Ha quanto tempo esta conversa espera resposta.
     *
     * Conta da ULTIMA MENSAGEM DO CLIENTE, e nao da abertura: conversa aberta ha tres dias em
     * que ele escreveu agora nao esta esperando ha tres dias. E devolve null quando a ultima
     * palavra foi nossa — ai a bola esta com ele, e cobrar o atendente por isso seria
     * inventar atraso.
     */
    public static function esperandoHa(\App\Models\Conversation $c): ?int
    {
        if (! $c->ultima_entrada_em) {
            return null;
        }

        $ultima = $c->ultimaMensagem;

        if ($ultima && ! $ultima->entrada()) {
            return null;
        }

        return (int) $c->ultima_entrada_em->diffInMinutes(now());
    }

    public function limparSelecao(): void
    {
        $this->selecionada = null;
    }

    public function selecionarBalde(string $balde): void
    {
        $this->limite = self::PAGINA;

        if (array_key_exists($balde, self::BALDES)) {
            $this->balde = $balde;
        }
    }

    public function selecionarEquipe(string $equipe): void
    {
        $this->limite = self::PAGINA;

        $this->equipe = $equipe;
    }

    public function selecionarOrdem(string $ordem): void
    {
        $this->limite = self::PAGINA;

        if (array_key_exists($ordem, self::ORDENS)) {
            $this->ordem = $ordem;
        }
    }

    // Fila se atende por ordem de chegada: em Novos, quem espera mais aparece
    // primeiro. Nos outros baldes o que importa e a conversa que se moveu agora.
    public function filtrarEtiqueta(?string $id): void
    {
        $n = (int) $id;

        // Etiqueta apagada noutra aba volta para "todas": lista vazia sem explicacao
        // parece defeito, nao filtro sem resultado.
        $this->etiqueta = $n > 0 && Tag::whereKey($n)->exists() ? (string) $n : null;

        // Trocar de recorte comeca outra lista: manter a pagina 3 mostraria um pedaco
        // do meio de algo que o atendente nunca viu do comeco.
        $this->limite = self::PAGINA;
    }

    private function etiquetaId(): ?int
    {
        $n = (int) $this->etiqueta;

        return $n > 0 ? $n : null;
    }

    public function carregarMais(): void
    {
        $this->limite += self::PAGINA;
    }

    // Busca e filtro de nao lidas vem de wire:model, entao usam os hooks do
    // Livewire; balde, equipe e ordem passam por metodo e reiniciam lá.
    public function updatedBusca(): void
    {
        $this->limite = self::PAGINA;
    }

    public function updatedCanal(): void
    {
        // Volta o limite ao inicio: sem isto, trocar o recorte mantem "carregar mais" de
        // antes e a lista mostra um pedaco do meio.
        $this->limite = self::PAGINA;
    }

    public function updatedSomenteNaoLidas(): void
    {
        $this->limite = self::PAGINA;
    }

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

        // Em fila, nao aqui: a tela nao pode esperar uma chamada HTTP para a
        // Evolution para abrir a conversa.
        \App\Jobs\MarcarLidaNoWhatsapp::dispatch($conversa->id);

        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function marcarSelecionada(int $conversationId): void
    {
        $this->selecionada = $conversationId;
    }

    private function doBalde(string $balde): Builder
    {
        $eu = auth()->id();

        // Grupo fica FORA da fila de atendimento: grupo e quase sempre equipe interna,
        // e revenda — volume alto e quase nada exige atendimento individual. Em
        // Novos, 30 mensagens de grupo enterrariam quem esta esperando resposta.
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

    // Equipe e recorte, nao balde: baldes sao fixos (palavra ou icone) e
    // equipes sao dados que variam. Cinco equipes como cinco baldes
    // explodiriam a barra.
    private function aplicarEquipe(Builder $query): Builder
    {
        $minhas = auth()->user()?->equipeIds() ?? [];

        // Quem nao esta em equipe nenhuma continua vendo tudo. Sem isto, o dia
        // em que a primeira equipe fosse criada, todo mundo fora dela ficaria
        // com o inbox vazio sem entender por que.
        if ($this->equipe === 'minhas' && $minhas === []) {
            return $query;
        }

        return match ($this->equipe) {
            'todas'  => $query,
            'sem'    => $query->whereNull('team_id'),
            'minhas' => $query->where(fn ($q) => $q->whereIn('team_id', $minhas)->orWhereNull('team_id')),
            default  => ctype_digit($this->equipe)
                ? $query->where('team_id', (int) $this->equipe)
                : $query,
        };
    }

    /**
     * Recortes PERSISTENTES: equipe e etiqueta.
     *
     * Os badges usam este, e nao o aplicarRecortes, porque busca e "apenas nao lidas"
     * sao transitorios — mas badge que conta conversa fora do recorte manda o
     * atendente procurar o que a lista nem mostra.
     */
    private function aplicarEscopo(Builder $query): Builder
    {
        $query = $this->aplicarEquipe($query);

        if ($id = $this->etiquetaId()) {
            // O filtro segue o CONTEXTO da etiqueta escolhida. "Cliente VIP" filtra pelo
            // contato; "Orcamento enviado" filtra pela conversa. Sem isso, escolher uma
            // etiqueta de conversa devolveria lista vazia e pareceria defeito.
            $daConversa = \App\Models\Tag::whereKey($id)->value('contexto') === \App\Models\Tag::CONVERSA;

            $query->whereHas($daConversa ? 'tags' : 'contact.tags', fn ($t) => $t->whereKey($id));
        }

        if ($this->canal !== null && $this->canal !== '') {
            $query->where('channel_id', (int) $this->canal);
        }

        return $query;
    }

    private function aplicarRecortes(Builder $query): Builder
    {
        $query = $this->aplicarEscopo($query);

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
        // os badges tambem respeitam a equipe escolhida, senao contariam
        // conversa que a lista nem mostra
        $badges = [
            'novos'      => $this->aplicarEscopo($this->doBalde('novos'))->count(),
            'meus'       => $this->aplicarEscopo($this->doBalde('meus'))->where('nao_lidas', '>', 0)->count(),
            'outros'     => $this->aplicarEscopo($this->doBalde('outros'))->where('nao_lidas', '>', 0)->count(),
            'grupos'     => $this->aplicarEscopo($this->doBalde('grupos'))->where('nao_lidas', '>', 0)->count(),
            'arquivadas' => null,
        ];

        // Conta antes de limitar: e o total que permite dizer quantas ficaram
        // de fora. Sem ele a lista truncaria calada.
        $total = $this->aplicarRecortes($this->doBalde($this->balde))->count();

        $conversas = $this->aplicarRecortes(
            $this->doBalde($this->balde)
                ->with(['contact.tags', 'ultimaMensagem', 'atendente', 'team', 'channel'])
                ->withCount('messages')
        )
            // Fixadas primeiro, e so as fixadas por QUEM ESTA OLHANDO. Conversa que outro
            // atendente prendeu no topo dele nao pode ocupar o topo do meu.
            ->orderByRaw('case when fixada_em is not null and fixada_por = ? then 0 else 1 end', [auth()->id()])
            ->orderBy('ultima_msg_em', $this->ordemEfetiva() === 'antigos' ? 'asc' : 'desc')
            ->limit($this->limite)
            ->get();

        return view('livewire.inbox.conversation-list', [
            'conversas'     => $conversas,
            'total'         => $total,
            'restantes'     => max(0, $total - $conversas->count()),
            'equipes'       => Team::ativas()->orderBy('nome')->get(),
            'etiquetas'     => Tag::orderBy('nome')->get(),
            'canais'        => $canais = \App\Models\Channel::orderBy('nome')->get(),
            // A marca de canal so aparece com MAIS DE UM canal. Com um so ela nao separa
            // nada e viraria enfeite ocupando o lugar de informacao util.
            'multiCanal'    => $canais->count() > 1,
            'etiquetaAtiva' => $this->etiquetaId() ? Tag::find($this->etiquetaId()) : null,
            'badges'        => $badges,
            'baldes'        => self::BALDES,
            'ordens'        => self::ORDENS,
            'ordemEfetiva'  => $this->ordemEfetiva(),
        ]);
    }
}
