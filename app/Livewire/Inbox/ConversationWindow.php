<?php

namespace App\Livewire\Inbox;

use App\Models\Conversation;
use Livewire\Component;

class ConversationWindow extends Component
{
    use \App\Livewire\Inbox\Concerns\CriaEtiquetaNoLocal;
    public ?int $conversationId = null;
    #[\Livewire\Attributes\Locked] public array $traducoes = [];
    #[\Livewire\Attributes\Locked] public array $idiomasTraducoes = [];
    public array $errosTraducao = [];
    public function traduzirMensagem(int $id,string $idioma='pt_BR'): void {
        $m=\App\Models\Message::findOrFail($id);
        abort_unless($m->conversation_id===$this->conversationId,403);
        unset($this->errosTraducao[$id]);
        try {$this->traducoes[$id]=app(\App\Services\TradutorMensagem::class)->traduzir($m,auth()->user(),$idioma);$this->idiomasTraducoes[$id]=$idioma;}
        catch(\RuntimeException $e){$this->errosTraducao[$id]=$e->getMessage();}
        catch(\Illuminate\Http\Client\ConnectionException $e){$this->errosTraducao[$id]=__('A tradução demorou para responder. Tente novamente.');}
    }
    public function ocultarTraducao(int $id): void {unset($this->traducoes[$id],$this->errosTraducao[$id],$this->idiomasTraducoes[$id]);}


    /** Texto procurado dentro da conversa aberta. */
    public string $buscaNaConversa = '';

    /** Qual mensagem esta esperando um destino para ser encaminhada. */
    public ?int $encaminhando = null;

    public string $buscaEncaminhar = '';

    public ?int $editandoNota = null;

    public string $textoNota = '';

    public ?int $editandoMensagem = null;

    public string $textoMensagem = '';

    /** Quantas mensagens por vez. Constante porque tres lugares precisam voltar a ela. */
    public const PAGINA = 30;

    public int $limite = self::PAGINA;

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
        $this->classificando=false;
        $this->reset('assuntoEncerramento','resumoEncerramento','solucaoEncerramento','pendenciasEncerramento','emailEncerramento');
    }

    public function abrir(int $conversationId): void
    {
        $this->traducoes = []; $this->errosTraducao = []; $this->idiomasTraducoes = [];
        $this->conversationId = $conversationId;
        $this->limite = 30;
        $this->buscaNaConversa = '';
    }

    /**
     * Atualiza SO quando a mensagem e desta conversa.
     *
     * A ponte avisa toda mensagem da conta, porque a lista lateral precisa de todas.
     * Refazer a janela por mensagem de outra conversa seria um request por mensagem
     * de um atendimento que ninguem esta olhando.
     */
    public function talvezAtualizar(?int $conversationId = null): void
    {
        if ($conversationId !== null && $conversationId !== $this->conversationId) {
            return;
        }

        // Sem corpo: o render ja refaz a lista de mensagens.
    }

    public function getListeners(): array
    {
        $listeners = [
            'abrir-conversa' => 'abrir',
            // O cabecalho mostra nome e etiquetas do contato. Sem escutar isso, trocar
            // a etiqueta no painel deixaria o cabecalho mostrando o estado anterior —
            // duas partes da mesma tela discordando.
            //
            // Evento proprio, e nao o 'conversa-atualizada': este componente TAMBEM
            // dispara aquele (assumir, transferir, finalizar), e escutar o que se
            // dispara custa um ida-e-volta extra a cada acao.
            'contato-atualizado' => '$refresh',

            // Mensagem nova, vinda da ponte em resources/js/app.js.
            'mensagem-chegou' => 'talvezAtualizar',

            // Os atalhos chamam os MESMOS metodos dos botoes. Um caminho separado para o
            // teclado seria uma segunda regra para manter, e a primeira a ficar para tras.
            'atalho-finalizar' => 'finalizar',
            'atalho-nao-lida' => 'marcarNaoLida',
        ];



        return $listeners;
    }

    public function carregarMais(): void
    {
        $this->limite += 30;
    }

    public function render()
    {
        $conversa = $this->conversationId
            ? Conversation::with(['contact.tags', 'tags'])->find($this->conversationId)
            : null;

        $procurado = trim($this->buscaNaConversa);

        $mensagens = $conversa
            ? $conversa->messages()
                ->with('autor')
                ->when($procurado !== '', function ($q) use ($procurado) {
                    // Procura tambem na legenda e na transcricao: audio transcrito e documento
                    // com legenda sao conteudo que o cliente mandou, e nao achar neles faria a
                    // busca parecer quebrada justamente em quem usa audio.
                    $q->where(function ($w) use ($procurado) {
                        $w->where('corpo', 'ilike', '%'.$procurado.'%')
                            ->orWhere('legenda', 'ilike', '%'.$procurado.'%')
                            ->orWhere('transcricao', 'ilike', '%'.$procurado.'%')
                            ->orWhere('media_nome', 'ilike', '%'.$procurado.'%');
                    });
                })
                ->orderByDesc('id')->limit($this->limite)->get()->reverse()->values()
            : collect();

        // Antes de existir user_id, a autoria manual ia para a primeira linha do texto
        // como "*Rafael*". Reconhecemos somente esse formato exato, sem mexer no historico,
        // para que a nova identidade visual tambem explique as mensagens ja enviadas.
        if ($conversa && $mensagens->isNotEmpty()) {
            $porPrimeiroNome = \App\Models\User::where('tenant_id', $conversa->tenant_id)
                ->get()->keyBy(fn (\App\Models\User $u) => mb_strtolower($u->primeiroNome()));

            $mensagens->each(function (\App\Models\Message $mensagem) use ($porPrimeiroNome) {
                if ($mensagem->entrada() || $mensagem->autor || ! preg_match('/^\\*([^*\\r\\n]+)\\*/u', (string) ($mensagem->corpo ?: $mensagem->legenda), $match)) {
                    return;
                }

                if ($autor = $porPrimeiroNome->get(mb_strtolower(trim($match[1])))) {
                    $mensagem->setRelation('autor', $autor);
                }
            });
        }

        $equipes = \App\Models\Team::ativas()->orderBy('nome')->get();

        // Quem pode receber a conversa. O escopo global ja limita a conta; tirar quem ja e o
        // atendente evita oferecer "passar para quem ja esta com ela", que nao faz nada.
        $pessoas = \App\Models\User::query()->where('perfil', '!=', 'auditor')
            ->when($conversa?->atendente_id, fn ($q, $id) => $q->whereKeyNot($id))
            ->orderBy('name')
            ->get();

        // So os eventos que caem dentro do trecho de mensagens carregado. Sem
        // esse corte, evento antigo apareceria acima do "carregar anteriores" e a
        // linha do tempo ficaria mentindo sobre a ordem.
        // Buscando, a linha do tempo mostra so mensagens: evento de transferencia e nota no
        // meio de um resultado de busca confunde mais do que ajuda, porque nao casam com o que
        // foi procurado.
        $desde = $procurado !== '' ? null : $mensagens->first()?->created_at;

        $eventos = ($conversa && $procurado === '')
            ? $conversa->events()->when(! auth()->user()?->consultaNotas(), fn ($q) => $q->where('tipo', '!=', \App\Models\ConversationEvent::NOTA))
                ->with('user')
                ->whereNull('dados->message_id')
                ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
                ->get()
            : collect();

        $notasPorMensagem = ($conversa && $procurado === '' && auth()->user()?->consultaNotas())
            ? $conversa->events()->with('user')
                ->where('tipo', \App\Models\ConversationEvent::NOTA)
                ->whereNotNull('dados->message_id')
                ->get()->groupBy(fn ($nota) => (int) data_get($nota->dados, 'message_id'))
            : collect();

        /*
         * A REUNIAO TAMBEM ENTRA NO FIO.
         *
         * O bate-papo da sala fica gravado, mas ate agora ninguem via depois que a chamada
         * acabava: o numero de serie que o cliente digitou existia no banco e nao existia na
         * tela. Aqui ele volta para onde a pessoa vai procurar — no meio do atendimento, na
         * hora em que a chamada aconteceu.
         */
        $reunioes = ($conversa && $procurado === '')
            ? $conversa->meetings()
                ->with(['messages', 'criador'])
                ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
                ->get()
            : collect();

        // Mensagem e evento no mesmo fio: nota e transferencia fora do lugar
        // cronologico nao servem para entender o que aconteceu.
        // Chave composta, e nao sortBy([...]): com array de closures o Laravel
        // trata cada uma como COMPARADOR ($a, $b), nao como extrator de chave —
        // a funcao recebe so $a, devolve sempre um positivo e a ordem embaralha.
        // Zeros a esquerda para o texto ordenar igual a numero.
        $linha = $mensagens
            ->concat($eventos)
            ->concat($reunioes)
            ->sortBy(fn ($item) => sprintf(
                '%011d-%d-%011d',
                $item->created_at?->getTimestamp() ?? 0,
                match (true) {
                    $item instanceof \App\Models\Message => 0,
                    $item instanceof \App\Models\ConversationEvent => 1,
                    default => 2,
                },
                $item->id,
            ))
            ->values();

        // So as de CONVERSA. As de contato nem aparecem aqui: nao da para marcar no lugar
        // errado se o lugar errado nao esta na lista.
        $etiquetasDeConversa = \App\Models\Tag::deConversa()->orderBy('nome')->get();

        // Candidatos a receber o encaminhamento. So busca com pelo menos duas letras: a
        // lista inteira de contatos num menu nao ajuda ninguem a achar alguem.
        $paraEncaminhar = ($this->encaminhando && mb_strlen(trim($this->buscaEncaminhar)) >= 2)
            ? \App\Models\Contact::ativos()
                ->where('id', '!=', $conversa?->contact_id)
                ->where('nome', 'ilike', '%'.trim($this->buscaEncaminhar).'%')
                ->orderBy('nome')->limit(8)->get()
            : collect();

        $podeApagar = $this->canalApaga();
        $podeReagir = $this->canalReage();
        $podeEditar = $this->canalEdita();

        return view('livewire.inbox.conversation-window', compact('conversa', 'mensagens', 'equipes', 'pessoas', 'eventos', 'linha', 'notasPorMensagem', 'podeApagar', 'podeReagir', 'podeEditar', 'etiquetasDeConversa', 'procurado', 'paraEncaminhar'));
    }

    public function assumir(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);
        $conversa->assumir();

        $this->dispatch('conversa-atualizada');
    }

    /** Fica true enquanto a tela pergunta como o atendimento terminou. */
    public bool $classificando = false;
    public string $assuntoEncerramento = '';
    public string $resumoEncerramento = '';
    public string $solucaoEncerramento = '';
    public string $pendenciasEncerramento = '';
    public string $emailEncerramento = '';
    public bool $enviarResumoCliente = true;
    public bool $enviarPesquisa = false;
    public ?int $avaliacaoCliente = null;
    public function reenviarResumo(): void {
        $conversa=\App\Models\Conversation::findOrFail($this->conversationId);
        abort_unless(auth()->user()?->podeVer($conversa) && auth()->user()?->consultaRelatorios() && !auth()->user()?->ehAuditor(),403);
        $db=\Illuminate\Support\Facades\DB::class;
        $fim=$db::table('encerramentos_atendimento')->where('conversation_id',$conversa->id)->orderByDesc('versao')->first();
        if(!$fim) return;
        if($fim->resumo_estado==='falhou') {
            if($db::table('encerramentos_atendimento')->where('id',$fim->id)->where('resumo_estado','falhou')->update(['resumo_estado'=>'preparando','updated_at'=>now()]))
                \App\Jobs\GerarResumoEncerramento::dispatch($fim->id);
        } else {
            $db::table('emails_encerramento')->where('encerramento_id',$fim->id)->where('estado','falhou')->update(['estado'=>'pendente','tentativas'=>0,'proxima_tentativa_em'=>null,'updated_at'=>now()]);
        }
    }
    public function prepararResumo(): void {
        $conversa=\App\Models\Conversation::findOrFail($this->conversationId);
        abort_unless(auth()->user()?->podeVer($conversa),403);
        abort_if(auth()->user()?->ehAuditor(),403);
        $this->emailEncerramento=$conversa->contact->email??'';
        $this->enviarResumoCliente=false;
        $this->avaliacaoCliente=null;
        $this->enviarPesquisa=false;
        $this->reset('assuntoEncerramento','resumoEncerramento','solucaoEncerramento','pendenciasEncerramento');
        $this->classificando=true;
    }

    public function gerarResumoIA(): void {
        $conversa=\App\Models\Conversation::findOrFail($this->conversationId);
        abort_if(auth()->user()?->ehAuditor(),403);
        abort_unless(auth()->user()?->podeVer($conversa),403);
        try {
            $d=app(\App\Services\ResumoIA::class)->gerar($conversa,auth()->user());
            $this->assuntoEncerramento=$d['assunto'];$this->resumoEncerramento=$d['resumo'];
            $this->solucaoEncerramento=$d['solucao'];$this->pendenciasEncerramento=$d['pendencias'];
            \Filament\Notifications\Notification::make()->success()->title(__('ui_e7fd7bbe1bce'))->send();
        } catch(\RuntimeException $e) {
            \Filament\Notifications\Notification::make()->warning()->title(__('ui_38c15f510bf8'))->body($e->getMessage())->send();
        } catch(\Throwable $e) {
            \Filament\Notifications\Notification::make()->warning()->title(__('ui_1dcf94806f30'))->body(__('ui_95a180d4d1d8'))->send();
        }
    }

    /**
     * Pergunta ANTES de arquivar.
     *
     * Etiqueta que so existe se alguem lembrar de aplicar e preenchida em uns 20% dos
     * atendimentos — e 20% nao vira numero em que alguem confia. Pedir no fecho e o unico
     * momento em que a pessoa tem a resposta na cabeca.
     *
     * MAS DEIXA PULAR. Obrigar faria o atendente com pressa clicar sempre na primeira opcao, e
     * ai o dado mente de um jeito pior: parece preenchido.
     *
     * Sem etiqueta de conversa cadastrada, nem pergunta — perguntar com a lista vazia so
     * atrapalha quem ainda nao configurou nada.
     */
    public function finalizar(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $this->prepararResumo();
    }

    /** Classifica e encerra, num movimento so. */
    public function encerrarCom(int $tagId): void
    {
        $this->validarResumo();
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $this->alternarEtiquetaDaConversa($tagId);
        $this->encerrar();
    }

    private function validarResumo(): void
    {
        $this->validate([
            'assuntoEncerramento'=>'nullable|string|max:180',
            'resumoEncerramento'=>'nullable|string|max:6000',
            'solucaoEncerramento'=>'nullable|string|max:6000',
            'pendenciasEncerramento'=>'nullable|string|max:3000',
            'emailEncerramento'=>'nullable|email|max:255',
            'avaliacaoCliente'=>'nullable|integer|between:1,5',
            'enviarResumoCliente'=>'boolean',
            'enviarPesquisa'=>'boolean',
        ]);
    }

    public function encerrar(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);
        $this->validarResumo();
        if ($conversa->status === \App\Models\Conversation::ARQUIVADA) return;
        if(config('agenda.edition')) {
            abort_unless(auth()->user()->podeVer($conversa),403);
            abort_if($conversa->contact->eGrupo(),422);
            \Illuminate\Support\Facades\DB::transaction(function()use($conversa){
                $fresh=\App\Models\Conversation::whereKey($conversa->id)->lockForUpdate()->firstOrFail();
                if($fresh->status===\App\Models\Conversation::ARQUIVADA)return;
                $fresh->arquivar();
                \App\Models\ConversationEvent::create(['conversation_id'=>$fresh->id,'user_id'=>auth()->id(),'tipo'=>'encerramento','descricao'=>'Atendimento finalizado.']);
            });
            $this->classificando=false;$this->dispatch('conversa-atualizada');return;
        }
        app(\App\Services\EncerrarComResumo::class)->registrar($conversa,[
            'automatico'=>trim($this->resumoEncerramento)==='',
            'assunto'=>$this->assuntoEncerramento ?: 'Atendimento '.$conversa->protocolo,'resumo'=>$this->resumoEncerramento ?: 'Resumo em preparação.',
            'solucao'=>$this->solucaoEncerramento,'pendencias'=>$this->pendenciasEncerramento,
            'email_cliente'=>$this->emailEncerramento,
            'enviar_resumo_cliente'=>$this->enviarResumoCliente,
            'avaliacao_cliente'=>$this->avaliacaoCliente,
            'enviar_pesquisa'=>$this->enviarPesquisa || (bool)$conversa->tenant?->pesquisa_obrigatoria,
        ],auth()->user());
        \Filament\Notifications\Notification::make()->success()->title(__('ui_98502c6b783b'))
            ->body(__('ui_e54ea4955f52'))->send();

        $conversa->refresh();

        // Depois de arquivar, nao antes: se a pergunta saisse primeiro, o cliente responderia
        // numa conversa ainda aberta e a nota entraria como conversa normal.
        // No widget encerrado nao existe campo para responder a pesquisa; mostrar a pergunta
        // e bloquear a resposta seria uma promessa quebrada. Ali vai apenas o aviso final.
        if (! $conversa->channel?->ehSite() && ($this->enviarPesquisa || $conversa->tenant?->pesquisa_obrigatoria)) {
            app(\App\Services\PesquisaDeSatisfacao::class)->perguntar($conversa->refresh(),true);
        }

        // E a jornada de pos-atendimento, se houver alguma ligada.
        app(\App\Services\Cadenciador::class)->inscrever(
            \App\Models\Sequence::ATENDIMENTO_ENCERRADO,
            $conversa->contact,
            $conversa,
        );

        // Deve ser a ultima transicao: criar uma mensagem de saida normalmente move a conversa
        // para "em atendimento". O servico publica o aviso e reafirma o arquivamento em uma
        // operacao idempotente, para painel e widget terminarem no mesmo estado.
        app(\App\Services\EncerramentoChatDoSite::class)->avisar($conversa->refresh());

        $this->classificando = false;
        $this->reset('assuntoEncerramento','resumoEncerramento','solucaoEncerramento','pendenciasEncerramento','emailEncerramento');

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Chama o cliente por video, de dentro da conversa.
     *
     * O CASO REAL E O ATENDIMENTO QUE EMPACOU NO TEXTO. Alguem descreve por quinze minutos um
     * problema que a camera resolveria em trinta segundos — "me mostra o aparelho" — e a saida
     * era pedir o telefone e ligar, saindo do sistema e do registro.
     *
     * O LINK VAI PELA CONVERSA, e nao por e-mail: a pessoa ja esta com o WhatsApp aberto, e
     * link que chega em outro lugar e link que ela abre amanha. Isso tambem deixa a chamada
     * REGISTRADA no historico do atendimento, como qualquer outra coisa que foi dita.
     *
     * AS REGRAS MORAM NO SERVICO, e nao aqui: a tela de Reunioes abre sala pelas mesmas
     * regras, e duas copias divergiriam na primeira correcao.
     */
    public function chamarPorVideo(\App\Services\Video\Chamada $chamada): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        if (! $chamada->disponivel()) {
            $this->addError('video', 'A chamada de vídeo não está configurada neste servidor.');

            return;
        }

        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        $reuniao = $chamada->paraConversa($conversa, auth()->id());

        match ($chamada->avisar($reuniao)) {
            \App\Services\Video\Chamada::JANELA_FECHADA => $this->addError(
                'video',
                'A janela de 24 horas fechou: copie o link e mande por fora — '.$reuniao->url(),
            ),
            \App\Services\Video\Chamada::FALHOU => $this->addError(
                'video',
                'Não consegui mandar o link pela conversa. Copie e mande por fora: '.$reuniao->url(),
            ),
            default => $this->dispatch('conversa-atualizada'),
        };

        // Nova aba: a conversa fica aberta atras. Trocar a tela do atendente pela sala faria
        // ele perder de vista o que o cliente escreveu ate agora.
        $this->dispatch('abrir-sala', url: $reuniao->url());
    }

    public function reabrir(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        if (! $conversa->reabrir()) {
            $this->addError('reabrir', 'Ja existe uma conversa aberta com este contato. Use aquela.');

            return;
        }

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Arma a citacao no compositor.
     *
     * Passa pelo servidor em vez de um $dispatch do Alpine porque aqui ha uma conferencia a
     * fazer — a mensagem tem de ser desta conversa — e conferencia que mora so no navegador
     * nao e conferencia.
     */
    public function responder(int $messageId): void
    {
        $mensagem = \App\Models\Message::find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        $this->dispatch('responder-a', messageId: $messageId);
    }

    public function anotar(int $messageId): void
    {
        $mensagem = \App\Models\Message::find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        $this->dispatch('anotar-mensagem', messageId: $messageId);
    }

    public function editarNota(int $notaId): void
    {
        $nota = $this->notaEditavel($notaId);
        if (! $nota) return;

        $this->editandoNota = $nota->id;
        $this->textoNota = $nota->descricao;
    }

    public function salvarEdicaoNota(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $nota = $this->notaEditavel((int) $this->editandoNota);
        if (! $nota) return;

        $this->validate(['textoNota' => 'required|string|max:4000']);
        $nota->update(['descricao' => trim($this->textoNota)]);
        $this->reset(['editandoNota', 'textoNota']);
    }

    public function excluirNota(int $notaId): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $nota = $this->notaEditavel($notaId);
        if ($nota) $nota->delete();
    }

    private function notaEditavel(int $notaId): ?\App\Models\ConversationEvent
    {
        $nota = \App\Models\ConversationEvent::find($notaId);
        $usuario = auth()->user();

        if (! $nota || $nota->conversation_id !== $this->conversationId || ! $nota->ehNota()) {
            return null;
        }

        return ($nota->user_id === $usuario?->id || $usuario?->veTudo()) ? $nota : null;
    }

    public function passarPara(int $userId): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        // find sob o escopo global: usuario de outro tenant nao existe nesta consulta. E a
        // mesma defesa do transferir para equipe, e vale mais aqui — passar uma conversa para
        // alguem de outra empresa daria a ela a conversa inteira.
        $destino = \App\Models\User::find($userId);

        if (! $destino || ! $conversa->passarPara($destino)) {
            $this->addError('transferir', 'Não consegui passar para essa pessoa.');

            return;
        }

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Reage a uma mensagem. Emoji vazio tira a reacao.
     *
     * Grava ANTES de mandar, para o clique responder na hora: reacao e gesto rapido, e esperar
     * a fila para ver o polegar aparecer faria a pessoa clicar de novo. Se o envio falhar, o
     * job desfaz e a reacao some da tela.
     */
    public function reagir(int $messageId, string $emoji = ''): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $mensagem = \App\Models\Message::find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        if (! $this->canalReage()) {
            return;
        }

        // Clicar no mesmo emoji de novo tira a reacao, como no WhatsApp.
        $novo = ($emoji !== '' && $mensagem->reacao_nossa === $emoji) ? '' : $emoji;

        $mensagem->update(['reacao_nossa' => $novo ?: null]);

        \App\Jobs\SendReaction::dispatch($mensagem->id, $novo);

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Apaga uma mensagem NOSSA para todo mundo.
     *
     * So mensagem nossa: apagar a do cliente nao existe no WhatsApp — nem no aparelho dele nem
     * no nosso. Um botao para isso apagaria so aqui e faria o atendente achar que sumiu dos
     * dois lados.
     */
    public function apagar(int $messageId): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $mensagem = \App\Models\Message::find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        if ($mensagem->entrada()) {
            $this->addError('apagar', 'Só dá para apagar mensagem que você enviou.');

            return;
        }

        if (! $this->canalApaga()) {
            $this->addError('apagar', 'O WhatsApp oficial não permite apagar mensagens já enviadas.');

            return;
        }

        // Marca antes e desfaz se falhar, como na reacao: o balao some na hora, e volta se o
        // provedor recusar. Sumir so aqui e ficar la seria a pior das saidas.
        $mensagem->update(['apagada_em' => now()]);

        \App\Jobs\DeleteMessage::dispatch($mensagem->id);

        $this->dispatch('conversa-atualizada');
    }

    public function iniciarEdicaoMensagem(int $messageId): void
    {
        $mensagem = $this->mensagemEditavel($messageId);

        if (! $mensagem) {
            $this->addError('editarMensagem', 'Esta mensagem não pode mais ser editada.');
            return;
        }

        $this->editandoMensagem = $mensagem->id;
        $this->textoMensagem = (string) $mensagem->corpo;
    }

    public function cancelarEdicaoMensagem(): void
    {
        $this->reset(['editandoMensagem', 'textoMensagem']);
        $this->resetErrorBag('editarMensagem');
    }

    public function salvarEdicaoMensagem(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $mensagem = $this->mensagemEditavel((int) $this->editandoMensagem);

        if (! $mensagem) {
            $this->addError('editarMensagem', 'O prazo para editar terminou ou o canal não permite edição.');
            return;
        }

        $this->validate(['textoMensagem' => 'required|string|max:4000']);
        $novoTexto = trim($this->textoMensagem);

        if ($novoTexto === $mensagem->corpo) {
            $this->cancelarEdicaoMensagem();
            return;
        }

        try {
            $canal = $mensagem->conversation->channel;
            $driver = app(\App\Services\Canais\Enviadores::class)->para($canal);
            $driver->editar(
                $canal,
                $mensagem->conversation->contact->destinoWhatsApp(),
                ['external_id' => (string) $mensagem->external_id, 'minha' => true],
                $novoTexto,
            );
        } catch (\Throwable $e) {
            report($e);
            $this->addError('editarMensagem', 'O WhatsApp recusou a edição. Verifique o prazo e tente novamente.');
            return;
        }

        // O histórico local só muda depois da confirmação do provedor.
        $mensagem->update(['corpo' => $novoTexto]);
        $this->cancelarEdicaoMensagem();
        $this->dispatch('conversa-atualizada');
    }

    private function mensagemEditavel(int $messageId): ?\App\Models\Message
    {
        $mensagem = \App\Models\Message::with(['conversation.channel', 'conversation.contact'])->find($messageId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId
            || $mensagem->entrada() || $mensagem->apagada() || $mensagem->tipo !== 'text'
            || ! $mensagem->external_id || ! $this->canalEdita()
            || $mensagem->created_at?->lt(now()->subMinutes(15))) {
            return null;
        }

        return $mensagem;
    }

    /** Edição enviada ao cliente existe hoje apenas no canal Evolution/Baileys. */
    public function canalEdita(): bool
    {
        $canal = \App\Models\Conversation::find($this->conversationId)?->channel;

        return $canal
            && app(\App\Services\Canais\Enviadores::class)->para($canal)->nome() === 'evolution';
    }

    /** O canal desta conversa consegue apagar? A API oficial nao consegue. */
    public function canalApaga(): bool
    {
        $canal = \App\Models\Conversation::find($this->conversationId)?->channel;

        return $canal ? app(\App\Services\Canais\Enviadores::class)->para($canal)->podeApagar() : false;
    }

    /** O canal desta conversa consegue reagir? Messenger e Instagram nao tem esse endpoint. */
    public function canalReage(): bool
    {
        $canal = \App\Models\Conversation::find($this->conversationId)?->channel;

        return $canal ? app(\App\Services\Canais\Enviadores::class)->para($canal)->podeReagir() : false;
    }

    /**
     * "Volto nisso depois."
     *
     * Fecha a conversa junto, e isso e o ponto: marcar como nao lida e continuar com ela
     * aberta na frente nao quer dizer nada — o proximo clique na lista zeraria o contador de
     * novo e a marca teria durado dois segundos.
     */
    public function marcarNaoLida(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);
        $conversa->marcarNaoLida();

        $this->conversationId = null;

        $this->dispatch('conversa-atualizada');
        $this->dispatch('fechar-conversa');
        $this->dispatch('voltar-para-lista');
    }

    /**
     * Poe ou tira uma etiqueta DESTA CONVERSA.
     *
     * Passa pelo Etiquetador, o mesmo caminho do contato, do chatbot e dos futuros agentes:
     * quando uma etiqueta aparecer errada alguem vai perguntar quem colocou, e a resposta tem
     * de existir venha de onde vier.
     */
    public function alternarEtiquetaDaConversa(int $tagId): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::find($this->conversationId);

        if (! $conversa) {
            return;
        }

        $etiquetador = app(\App\Services\Etiquetador::class);

        if ($conversa->tags()->whereKey($tagId)->exists()) {
            $etiquetador->remover($conversa, [$tagId]);
        } else {
            // O Etiquetador recusa sozinho etiqueta de contato aqui: o contexto vem do TIPO do
            // alvo, e nao de quem chama.
            $etiquetador->aplicar($conversa, [$tagId], \App\Services\Etiquetador::MANUAL, auth()->id());
        }

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Busca dentro DESTA conversa.
     *
     * A busca da lista acha a conversa; esta acha a mensagem. Conversa de tres meses e
     * "o que ele falou do orcamento?" era rolar com o dedo ate achar — e quando nao achava, o
     * atendente perguntava de novo ao cliente, que ja tinha respondido.
     *
     * Filtra o trecho carregado no servidor e nao no navegador: as mensagens antigas nem estao
     * na tela, e uma busca que so acha o que ja esta visivel encontra menos do que a pessoa ve
     * rolando — pior que nao ter busca.
     */
    public function updatedBuscaNaConversa(): void
    {
        // Buscar recomeca do topo do resultado; manter o "carregar anteriores" de antes
        // mostraria um pedaco do meio de outra coisa.
        $this->limite = self::PAGINA;
    }

    public function limparBusca(): void
    {
        $this->buscaNaConversa = '';
        $this->limite = self::PAGINA;
    }

    public function alternarFixada(): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        $conversa->fixadaPara(auth()->user())
            ? $conversa->desafixar()
            : $conversa->fixarPara(auth()->user());

        $this->dispatch('conversa-atualizada');
    }

    /**
     * Encaminha uma mensagem para outro contato.
     *
     * O ARQUIVO E COPIADO, e nao apontado. Duas mensagens compartilhando o mesmo caminho
     * pareceriam economia ate o dia em que o primeiro contato pedisse a exclusao dos dados
     * dele pela LGPD: o arquivo sumiria do disco e a mensagem encaminhada, que e de OUTRA
     * pessoa e nao foi pedida, quebraria junto.
     */
    public function encaminhar(int $messageId, int $contactId): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $mensagem = \App\Models\Message::find($messageId);
        $destino = \App\Models\Contact::find($contactId);

        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId || ! $destino) {
            return;
        }

        if ($mensagem->apagada()) {
            $this->addError('encaminhar', 'Essa mensagem foi apagada.');

            return;
        }

        $origem = \App\Models\Conversation::findOrFail($this->conversationId);

        $conversa = \App\Models\Conversation::abertaOuNova(
            $origem->channel_id,
            $destino->id,
            $origem->tenant_id,
        );

        if (! $conversa->podeEnviarLivre()) {
            $this->addError('encaminhar', 'A janela de 24 horas com '.$destino->nomeExibicao().' está fechada.');

            return;
        }

        $caminho = null;

        if ($mensagem->media_path) {
            $caminho = 'media/'.\Illuminate\Support\Str::uuid().'.'
                .pathinfo($mensagem->media_path, PATHINFO_EXTENSION);

            if (! \Illuminate\Support\Facades\Storage::disk('local')->copy($mensagem->media_path, $caminho)) {
                $this->addError('encaminhar', 'Não consegui copiar o arquivo.');

                return;
            }
        }

        $nova = \App\Models\Message::create([
            'tenant_id'       => $origem->tenant_id,
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => $mensagem->tipo,
            'corpo'           => $mensagem->corpo,
            'legenda'         => $mensagem->legenda,
            'media_path'      => $caminho,
            'media_mime'      => $mensagem->media_mime,
            'media_nome'      => $mensagem->media_nome,
            'media_tamanho'   => $mensagem->media_tamanho,
            'media_duracao'   => $mensagem->media_duracao,
            'status'          => \App\Models\Message::STATUS_QUEUED,
        ]);

        $caminho
            ? \App\Jobs\SendMediaMessage::dispatch($nova->id)
            : \App\Jobs\SendTextMessage::dispatch($nova->id);

        $conversa->update(['ultima_msg_em' => now()]);

        $this->encaminhando = null;
        $this->buscaEncaminhar = '';

        $this->dispatch('conversa-atualizada');
        // Abre a conversa de destino: encaminhar e mandar para alguem, e a pessoa quase
        // sempre quer escrever uma linha junto.
        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    public function verDetalhes(): void
    {
        $this->dispatch('abrir-detalhes');
    }

    public function transferir(int $teamId): void
    {
        abort_if(auth()->user()?->ehAuditor(), 403, 'O perfil Auditor permite somente consulta.');
        $conversa = \App\Models\Conversation::findOrFail($this->conversationId);

        // ativas()->find() sob o escopo global: equipe de outro tenant nao existe aqui
        $destino = \App\Models\Team::ativas()->find($teamId);

        if (! $destino || ! $conversa->transferir($destino)) {
            $this->addError('transferir', 'Não consegui transferir para essa equipe.');

            return;
        }

        $this->dispatch('conversa-atualizada');
    }
}
