<?php

namespace App\Filament\Pages;

use App\Models\Appointment;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use App\Support\DataPtBr;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Agenda: compromisso com o cliente, e lembrete pessoal.
 *
 * CALENDARIO DE VERDADE — mes, semana, dia — porque e assim que as pessoas ja sabem ler uma
 * agenda. Quem abre espera achar o dia 23 no lugar onde o dia 23 sempre esteve.
 *
 * A GRADE E O QUE MOSTRA O BURACO. Uma lista diz o que tem marcado; so a grade responde "cabe
 * uma visita as 15h?", que e a pergunta de quem esta com o cliente no telefone. Por isso a
 * semana e a visao de entrada, e nao o mes: o mes mostra o que existe, a semana mostra onde
 * cabe.
 *
 * A VISAO LISTA CONTINUA, agrupada por urgencia. Ela responde outra coisa — "o que ficou para
 * tras" — e essa pergunta nao tem lugar numa grade, porque atraso nao e uma data, e uma
 * comparacao com agora.
 */
class Agenda extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Agenda';

    protected static ?string $title = 'Agenda';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'agenda';

    protected string $view = 'filament.pages.agenda';

    /** Calendario em coluna estreita nao e calendario: sete dias precisam de largura. */
    protected Width|string|null $maxContentWidth = Width::Full;

    /** Altura de uma hora na grade, em pixels. O dia inteiro sao 24 destes. */
    public const ALTURA_HORA = 48;

    /** Compromisso de 10 minutos ainda precisa caber o texto. */
    public const MINIMO_MIN = 30;

    public const VISOES = [
        'mes'    => 'Mês',
        'semana' => 'Semana',
        'dia'    => 'Dia',
        'lista'  => 'Lista',
        'link'   => 'Link',
    ];

    /** Visoes que nao sao calendario: sem seta de periodo, sem filtro de pessoa. */
    public const SEM_PERIODO = ['lista', 'link'];

    // Os mesmos nomes da pagina publica de reserva: duas listas de meses divergem no dia em
    // que alguem corrigir um acento numa so.
    public const MESES = DataPtBr::MESES;

    public const DIAS = DataPtBr::DIAS;

    public const DIAS_LONGOS = DataPtBr::DIAS_LONGOS;

    public string $visao = 'semana';

    /** O dia em que o calendario esta parado, no formato Y-m-d. */
    public string $cursor = '';

    /** Filtro de pessoa. Nulo mostra a agenda da equipe inteira. */
    public ?int $quem = null;

    public bool $formAberto = false;

    public ?int $editando = null;

    public string $titulo = '';

    public string $descricao = '';

    public string $tipo = Appointment::COMPROMISSO;

    public string $quando = '';

    public ?int $duracao_min = 60;

    public ?int $user_id = null;

    public ?int $contact_id = null;

    public string $buscaContato = '';

    /** Compromisso por video: abre sala e manda o link para quem foi convidado. */
    public bool $por_video = false;

    /** O que aconteceu com o convite, para a tela contar. */
    public ?string $recadoDoVideo = null;

    /**
     * Os convidados em edicao.
     *
     * Vivem em memoria ate salvar porque o compromisso NOVO ainda nao tem id: gravar convidado
     * antes exigiria criar o compromisso no primeiro clique e limpar depois se a pessoa
     * desistir — e compromisso fantasma na agenda de alguem e pior que formulario com estado.
     *
     * @var list<array{contact_id: ?int, nome: string, email: ?string}>
     */
    public array $convidados = [];

    public string $buscaConvidado = '';

    public string $emailNovo = '';

    /** A caixa de avisar por WhatsApp. */
    public bool $avisando = false;

    public ?int $canalDoAviso = null;

    /** @var list<int> */
    public array $paraAvisar = [];

    public string $buscaParaAvisar = '';

    /**
     * O numero vermelho no menu: o que ESTA ATRASADO.
     *
     * Nao conta o que e hoje mais tarde — isso ainda esta no prazo, e badge que acende por algo
     * no prazo e badge que se aprende a ignorar.
     */
    public static function getNavigationBadge(): ?string
    {
        $n = Appointment::query()
            ->visivelPara(auth()->user())
            ->pendentes()
            ->where('comeca_em', '<', now())
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        $this->cursor = now()->toDateString();
        $this->user_id = auth()->id();
        $this->quando = $this->agoraRedondo();

        // A visao escolhida gruda: quem trabalha no mes nao quer reescolher toda vez que abre.
        $guardada = session('agenda.visao');

        if (is_string($guardada) && array_key_exists($guardada, self::VISOES)) {
            $this->visao = $guardada;
        }
    }

    // ---------------------------------------------------------- navegacao

    public function verComo(string $visao): void
    {
        if (! array_key_exists($visao, self::VISOES)) {
            return;
        }

        $this->visao = $visao;
        session(['agenda.visao' => $visao]);
    }

    public function hoje(): void
    {
        $this->cursor = now()->toDateString();
    }

    /** Celula cheia no mes: em vez de espremer, abre o dia — que e onde tudo cabe. */
    public function verDia(string $dia): void
    {
        $this->cursor = Carbon::parse($dia)->toDateString();
        $this->verComo('dia');
    }

    public function anterior(): void
    {
        $this->andar(-1);
    }

    public function proximo(): void
    {
        $this->andar(1);
    }

    private function andar(int $n): void
    {
        $d = Carbon::parse($this->cursor);

        // addMonthsNoOverflow: 31 de janeiro mais um mes e 28 de fevereiro, e nao 3 de marco.
        $this->cursor = match ($this->visao) {
            'mes' => $d->addMonthsNoOverflow($n)->toDateString(),
            'dia' => $d->addDays($n)->toDateString(),
            default => $d->addWeeks($n)->toDateString(),
        };
    }

    // ------------------------------------------------------------- form

    public function novo(): void
    {
        $this->reset([
            'editando', 'titulo', 'descricao', 'contact_id', 'buscaContato', 'por_video',
            'recadoDoVideo', 'convidados', 'buscaConvidado', 'emailNovo',
        ]);
        $this->tipo = Appointment::COMPROMISSO;
        $this->duracao_min = 60;
        $this->user_id = auth()->id();
        $this->quando = $this->agoraRedondo();
        $this->formAberto = true;
    }

    /**
     * Clicou num buraco da grade.
     *
     * A hora vem do lugar onde a pessoa clicou, porque quem clica nas 15h de quinta ja disse
     * quando quer — pedir a data de novo num formulario e perguntar o que ela acabou de
     * responder.
     */
    public function novoEm(string $dia, ?int $minutos = null): void
    {
        $this->novo();

        $this->quando = Carbon::parse($dia)->startOfDay()
            ->addMinutes($minutos ?? 9 * 60)
            ->format('Y-m-d\TH:i');
    }

    public function editar(int $id): void
    {
        $a = Appointment::visivelPara(auth()->user())->find($id);

        if (! $a) {
            return;
        }

        $this->editando = $a->id;
        $this->titulo = $a->titulo;
        $this->descricao = (string) $a->descricao;
        $this->tipo = $a->tipo;
        $this->quando = $a->comeca_em->format('Y-m-d\TH:i');
        $this->duracao_min = $a->duracao_min;
        $this->user_id = $a->user_id;
        $this->contact_id = $a->contact_id;
        $this->buscaContato = (string) $a->contact?->nomeExibicao();
        $this->por_video = $a->ehPorVideo();
        $this->recadoDoVideo = null;

        $this->convidados = $a->guests->map(fn ($c) => [
            'contact_id' => $c->contact_id,
            'nome'       => $c->nome,
            'email'      => $c->email,
        ])->all();

        $this->reset(['buscaConvidado', 'emailNovo']);
        $this->formAberto = true;
    }

    public function salvar(): void
    {
        $this->validate([
            'titulo'      => 'required|string|max:120',
            'quando'      => 'required|date',
            'tipo'        => 'required|in:compromisso,lembrete',
            'duracao_min' => 'nullable|integer|min:5|max:1440',
            'user_id'     => 'required|integer',
        ], [], ['quando' => 'data e hora', 'user_id' => 'responsável']);

        // Lembrete e de quem escreve: marcar lembrete para outra pessoa seria por na cabeca
        // dela uma coisa que ela nunca vai ver, porque lembrete alheio nao aparece.
        $dono = $this->tipo === Appointment::LEMBRETE
            ? auth()->id()
            : (User::whereKey($this->user_id)->value('id') ?? auth()->id());

        $dados = [
            'user_id'     => $dono,
            'tipo'        => $this->tipo,
            'titulo'      => trim($this->titulo),
            'descricao'   => trim($this->descricao) ?: null,
            'comeca_em'   => Carbon::parse($this->quando),
            'duracao_min' => $this->tipo === Appointment::LEMBRETE ? null : $this->duracao_min,
            'contact_id'  => $this->contact_id,
        ];

        if ($this->editando) {
            $compromisso = Appointment::visivelPara(auth()->user())->findOrFail($this->editando);
            $compromisso->update($dados);
        } else {
            $compromisso = Appointment::create($dados + [
                'tenant_id'  => auth()->user()->tenant_id,
                'criado_por' => auth()->id(),
            ]);
        }

        $this->gravarConvidados($compromisso);

        $this->cuidarDoVideo($compromisso->refresh());

        // O calendario anda ate onde a coisa foi marcada: salvar e nao ver o que salvou parece
        // que nao salvou.
        $this->cursor = Carbon::parse($this->quando)->toDateString();

        $this->formAberto = false;
        $this->editando = null;
    }

    /**
     * Passa a lista da tela para o banco.
     *
     * QUEM SAIU DA LISTA E APAGADO, e por isso as datas de aviso se perdem junto — e o certo:
     * se a pessoa voltar para a lista depois, ela precisa ser avisada de novo, e um "avisado
     * em" sobrevivente diria que nao.
     *
     * updateOrCreate e nao create: salvar o compromisso duas vezes nao pode duplicar convidado
     * nem apagar quem ja foi avisado.
     */
    private function gravarConvidados(Appointment $compromisso): void
    {
        $vivos = [];

        foreach ($this->convidados as $c) {
            $chave = $c['contact_id']
                ? ['appointment_id' => $compromisso->id, 'contact_id' => $c['contact_id']]
                : ['appointment_id' => $compromisso->id, 'email' => $c['email']];

            $convidado = \App\Models\AppointmentGuest::updateOrCreate($chave + ['tenant_id' => $compromisso->tenant_id], [
                'nome'  => $c['nome'],
                'email' => $c['email'],
            ]);

            $vivos[] = $convidado->id;
        }

        $compromisso->guests()->whereKeyNot($vivos)->delete();
    }

    // -------------------------------------------------------------- avisar

    /**
     * O bloco de texto do convite, para a tela oferecer o copiar.
     *
     * Le do banco de proposito, sem gravar nada: isto roda a cada desenho da tela, e gravar
     * dentro de um metodo que so devolve texto e como escrita aparece onde ninguem procura.
     * O que ele mostra e o compromisso salvo — que e justamente o que o convite descreve.
     */
    public function textoDoConvite(): string
    {
        if (! $this->editando) {
            return '';
        }

        $compromisso = Appointment::visivelPara(auth()->user())->find($this->editando);

        return $compromisso ? app(\App\Services\Agendamento\Convite::class)->texto($compromisso) : '';
    }

    /**
     * O compromisso que os botoes de convite agem sobre, com a lista da TELA ja gravada.
     *
     * AQUI MORAVA UMA ARMADILHA. A lista de convidados vive em memoria ate salvar; os botoes
     * agiam sobre o que estava no BANCO. Quem adicionava um convidado e clicava em "enviar"
     * sem salvar antes mandava para a lista velha — ou para ninguem — e a tela dizia
     * "adicione convidados" com dois nomes na frente da pessoa. Fazer o botao agir sobre o que
     * esta na tela e o unico comportamento que nao mente.
     *
     * Devolve null quando nao ha compromisso salvo, e quem chama conta o porque.
     */
    private function paraConvidar_compromisso(): ?Appointment
    {
        if (! $this->editando) {
            return null;
        }

        $compromisso = Appointment::visivelPara(auth()->user())->find($this->editando);

        if (! $compromisso) {
            return null;
        }

        $this->gravarConvidados($compromisso);

        return $compromisso->refresh();
    }

    public function enviarPorEmail(): void
    {
        $compromisso = $this->paraConvidar_compromisso();

        if (! $compromisso) {
            // Silencio aqui era o pior desfecho: a pessoa clicava e nada acontecia na tela.
            $this->recadoDoVideo = 'Salve o compromisso antes de enviar o convite.';

            return;
        }

        $r = app(\App\Services\Agendamento\Convite::class)->porEmail($compromisso);

        if ($r['enviados'] === 0) {
            $this->recadoDoVideo = $r['sem_email'] > 0
                ? 'Nenhum convidado tem e-mail cadastrado. Use o copiar, ou avise pelo WhatsApp.'
                : 'Adicione convidados antes de enviar o convite.';

            return;
        }

        $this->recadoDoVideo = $r['enviados'] === 1
            ? 'Convite saindo por e-mail para 1 convidado.'
            : 'Convites saindo por e-mail para '.$r['enviados'].' convidados.';

        if ($r['sem_email'] > 0) {
            $this->recadoDoVideo .= ' '.$r['sem_email'].' sem e-mail cadastrado ficaram de fora.';
        }
    }

    /**
     * Abre a caixa de avisar por WhatsApp.
     *
     * Ja vem com os convidados que sao contatos marcados: eles sao a resposta certa em quase
     * todo caso, e desmarcar da menos trabalho que procurar cada um de novo.
     */
    public function abrirAviso(): void
    {
        $this->paraAvisar = collect($this->convidados)
            ->pluck('contact_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $canais = Channel::orderBy('nome')->get();

        // Com um canal so nao ha escolha a fazer; com mais de um, escolher pelo primeiro
        // mandaria do numero errado sem avisar.
        $this->canalDoAviso = $canais->count() === 1 ? $canais->first()->id : null;

        $this->buscaParaAvisar = '';
        $this->avisando = true;
    }

    public function alternarParaAvisar(int $contactId): void
    {
        $this->paraAvisar = in_array($contactId, $this->paraAvisar, true)
            ? array_values(array_diff($this->paraAvisar, [$contactId]))
            : [...$this->paraAvisar, $contactId];
    }

    public function avisarPorWhatsapp(): void
    {
        $compromisso = $this->paraConvidar_compromisso();

        if (! $compromisso) {
            $this->recadoDoVideo = 'Salve o compromisso antes de enviar o convite.';
            $this->avisando = false;

            return;
        }

        if (! $this->canalDoAviso) {
            $this->addError('canalDoAviso', 'Escolha por qual número enviar.');

            return;
        }

        if ($this->paraAvisar === []) {
            $this->addError('paraAvisar', 'Escolha pelo menos um contato.');

            return;
        }

        $canal = Channel::find($this->canalDoAviso);

        if (! $canal) {
            return;
        }

        $r = app(\App\Services\Agendamento\Convite::class)
            ->porWhatsapp($compromisso, $canal, $this->paraAvisar);

        $this->recadoDoVideo = $r['enviados'] === 1
            ? 'Convite enviado no WhatsApp de 1 contato.'
            : 'Convite enviado no WhatsApp de '.$r['enviados'].' contatos.';

        if ($r['fora'] !== []) {
            $this->recadoDoVideo .= ' Fora da janela de 24 horas, ficaram sem receber: '
                .implode(', ', $r['fora']).'. Copie o convite e mande por fora.';
        }

        // A lista pode ter crescido: quem foi avisado entrou como convidado.
        $this->convidados = $compromisso->refresh()->guests->map(fn ($c) => [
            'contact_id' => $c->contact_id,
            'nome'       => $c->nome,
            'email'      => $c->email,
        ])->all();

        $this->avisando = false;
    }

    /**
     * A sala do compromisso: abre, remarca ou desmarca conforme a caixinha.
     *
     * LEMBRETE NAO TEM SALA. Ele e um bilhete para si mesmo — abrir uma sala de video para
     * "ligar para o contador" nao faz sentido nenhum, e ainda mandaria link para o contador.
     *
     * O CONVITE SAI UMA VEZ, quando a sala nasce. Salvar o compromisso de novo para corrigir um
     * acento nao pode mandar o link outra vez: quem recebe dois convites da mesma reuniao fica
     * sem saber qual vale.
     */
    private function cuidarDoVideo(Appointment $compromisso): void
    {
        $chamada = app(\App\Services\Video\Chamada::class);
        $existente = $compromisso->meeting;

        // Desmarcou a caixinha, ou virou lembrete: a sala vai embora.
        if (! $this->por_video || $compromisso->ehLembrete()) {
            if ($existente) {
                $chamada->encerrar($existente);
                $this->recadoDoVideo = 'A sala de vídeo deste compromisso foi encerrada.';
            }

            return;
        }

        if (! $chamada->disponivel()) {
            $this->recadoDoVideo = 'A chamada de vídeo não está configurada neste servidor.';

            return;
        }

        // Ja tinha sala: so acompanha o horario, e nao convida de novo.
        if ($existente) {
            $chamada->sincronizarHorario($compromisso);

            return;
        }

        $reuniao = $chamada->paraCompromisso($compromisso);

        $this->recadoDoVideo = match ($chamada->avisar($reuniao, $chamada->convite($reuniao))) {
            \App\Services\Video\Chamada::AVISADO => 'Convite com o link enviado no WhatsApp de '
                .$compromisso->contact?->nomeExibicao().'.',
            \App\Services\Video\Chamada::JANELA_FECHADA => 'A janela de 24 horas fechou neste canal: '
                .'copie o link do compromisso e mande por fora.',
            \App\Services\Video\Chamada::SEM_CONVERSA => 'Sala criada. Copie o link no compromisso '
                .'e mande para quem vai participar.',
            default => 'Sala criada, mas não consegui mandar o link. Copie no compromisso e mande por fora.',
        };
    }

    /**
     * Poe um contato na lista de convidados.
     *
     * O contato do compromisso ("com quem e") e coisa diferente de convidado: da para marcar
     * uma visita com a Padaria do Ze e convidar o eletricista, que nao e o cliente.
     */
    public function convidarContato(int $id): void
    {
        $contato = Contact::find($id);

        if (! $contato) {
            return;
        }

        foreach ($this->convidados as $c) {
            if (($c['contact_id'] ?? null) === $contato->id) {
                $this->buscaConvidado = '';

                return;
            }
        }

        $this->convidados[] = [
            'contact_id' => $contato->id,
            'nome'       => $contato->nomeExibicao(),
            'email'      => $contato->email,
        ];

        $this->buscaConvidado = '';
    }

    /** Convidado que nao e contato do CRM: o socio, o fornecedor que aparece uma vez. */
    public function convidarEmail(): void
    {
        $email = mb_strtolower(trim($this->emailNovo));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('emailNovo', 'Esse e-mail não parece certo.');

            return;
        }

        foreach ($this->convidados as $c) {
            if (mb_strtolower((string) ($c['email'] ?? '')) === $email) {
                $this->emailNovo = '';

                return;
            }
        }

        $this->convidados[] = [
            'contact_id' => null,
            // Sem nome, o proprio e-mail serve: melhor que "Convidado 3" na lista.
            'nome'       => \Illuminate\Support\Str::before($email, '@'),
            'email'      => $email,
        ];

        $this->emailNovo = '';
        $this->resetErrorBag('emailNovo');
    }

    public function tirarConvidado(int $indice): void
    {
        unset($this->convidados[$indice]);

        $this->convidados = array_values($this->convidados);
    }

    public function escolherContato(int $id): void
    {
        $contato = Contact::find($id);

        if (! $contato) {
            return;
        }

        $this->contact_id = $contato->id;
        $this->buscaContato = $contato->nomeExibicao();
    }

    public function tirarContato(): void
    {
        $this->contact_id = null;
        $this->buscaContato = '';
    }

    // ------------------------------------------------------------ acoes

    /**
     * Arrastou para outro lugar da grade.
     *
     * Remarcar e a coisa mais comum que acontece com um compromisso, e arrastar e o gesto que
     * todo mundo ja tem na mao. Sem minutos — largou numa celula de mes — o horario fica onde
     * estava: quem arrasta de terca para quinta quer mudar o DIA, nao a hora.
     */
    public function mover(int $id, string $dia, ?int $minutos = null): void
    {
        $a = Appointment::visivelPara(auth()->user())->find($id);

        if (! $a) {
            return;
        }

        $minutos ??= $a->comeca_em->hour * 60 + $a->comeca_em->minute;

        $a->update([
            'comeca_em' => Carbon::parse($dia)->startOfDay()->addMinutes(max(0, min(1439, $minutos))),
        ]);

        // A sala anda com o compromisso. Sem isto, arrastar a visita de terca para quinta
        // deixaria o link vencendo na terca — e o cliente descobriria isso na quinta, na hora
        // de entrar.
        app(\App\Services\Video\Chamada::class)->sincronizarHorario($a->refresh());
    }

    public function concluir(int $id): void
    {
        $a = Appointment::visivelPara(auth()->user())->find($id);

        $a?->update(['concluido_em' => $a->concluido() ? null : now()]);
    }

    public function excluir(int $id): void
    {
        $a = Appointment::visivelPara(auth()->user())->find($id);

        if (! $a) {
            return;
        }

        // A sala morre com o compromisso: link de reuniao desmarcada que continua abrindo e
        // gente entrando numa sala que ninguem mais vai atender.
        if ($reuniao = $a->meeting) {
            app(\App\Services\Video\Chamada::class)->encerrar($reuniao);
        }

        $a->delete();

        $this->formAberto = false;
        $this->editando = null;
    }

    // ------------------------------------------------------------ dados

    /**
     * Outro compromisso da mesma pessoa em cima deste horario.
     *
     * AVISA, e nao impede. O horario ja fica travado onde importa — o link publico de
     * agendamento nao oferece vaga ocupada, e ninguem de fora consegue marcar em cima. Aqui
     * dentro, marcar duas coisas na mesma hora as vezes e proposital (a visita e a ligacao que
     * cabe no caminho), e recusar obrigaria a pessoa a mentir o horario para conseguir anotar.
     */
    public function conflitos(): \Illuminate\Support\Collection
    {
        if ($this->tipo === Appointment::LEMBRETE || ! $this->quando) {
            return collect();
        }

        $inicio = Carbon::parse($this->quando);
        $fim = $inicio->copy()->addMinutes(max(5, (int) ($this->duracao_min ?: 30)));

        return Appointment::query()
            ->where('user_id', $this->user_id ?: auth()->id())
            ->where('tipo', Appointment::COMPROMISSO)
            ->when($this->editando, fn ($q) => $q->whereKeyNot($this->editando))
            ->whereDate('comeca_em', $inicio->toDateString())
            ->get()
            ->filter(function (Appointment $a) use ($inicio, $fim) {
                $outroFim = $a->comeca_em->copy()->addMinutes(max(5, (int) ($a->duracao_min ?: 30)));

                return $a->comeca_em->lt($fim) && $outroFim->gt($inicio);
            })
            ->values();
    }

    public function getViewData(): array
    {
        // A aba do link nao e calendario: nao ha periodo para varrer, e varrer assim mesmo
        // seria uma consulta por nada em cada clique dentro dela.
        if ($this->visao === 'link') {
            return [
                'rotulo' => $this->rotulo(),
                'semanas' => [], 'colunas' => [], 'grupos' => [],
                'pessoas' => User::orderBy('name')->get(),
                'candidatos' => collect(),
                'agora' => now(),
                'paraConvidar' => collect(),
                'contatosDoAviso' => collect(),
                'canais' => collect(),
            ];
        }

        [$de, $ate] = $this->periodo();

        $doPeriodo = $this->consulta()
            ->whereBetween('comeca_em', [$de, $ate])
            ->orderBy('comeca_em')
            ->get();

        return [
            'rotulo'     => $this->rotulo(),
            'semanas'    => $this->visao === 'mes' ? $this->semanasDoMes($de, $ate, $doPeriodo) : [],
            'colunas'    => in_array($this->visao, ['semana', 'dia'], true)
                ? $this->colunas($de, $ate, $doPeriodo)
                : [],
            'grupos'     => $this->visao === 'lista' ? $this->porUrgencia() : [],
            'pessoas'    => User::orderBy('name')->get(),
            'candidatos' => $this->candidatos(),
            'agora'      => now(),
            'paraConvidar'    => $this->paraConvidar(),
            'contatosDoAviso' => $this->contatosDoAviso(),
            'canais'          => Channel::orderBy('nome')->get(),
        ];
    }

    /** Os contatos que a busca de convidado achou. */
    private function paraConvidar(): Collection
    {
        if (mb_strlen(trim($this->buscaConvidado)) < 2) {
            return collect();
        }

        return Contact::ativos()
            ->where('nome', 'ilike', '%'.trim($this->buscaConvidado).'%')
            ->orderBy('nome')->limit(8)->get();
    }

    /**
     * A lista da caixa de avisar por WhatsApp.
     *
     * Sem busca, mostra os ULTIMOS contatos e nao "todos": conta com dez mil contatos
     * desenharia dez mil linhas com caixinha, e a tela morre antes de aparecer.
     */
    private function contatosDoAviso(): Collection
    {
        if (! $this->avisando) {
            return collect();
        }

        return Contact::ativos()
            ->when(
                mb_strlen(trim($this->buscaParaAvisar)) >= 2,
                fn ($q) => $q->where('nome', 'ilike', '%'.trim($this->buscaParaAvisar).'%'),
            )
            ->orderByDesc('id')->limit(40)->get();
    }

    private function consulta()
    {
        return Appointment::query()
            ->visivelPara(auth()->user())
            ->when($this->quem, fn ($q) => $q->where('user_id', $this->quem))
            ->with(['user', 'contact']);
    }

    private function candidatos(): Collection
    {
        if ($this->contact_id || mb_strlen(trim($this->buscaContato)) < 2) {
            return collect();
        }

        return Contact::ativos()
            ->where('nome', 'ilike', '%'.trim($this->buscaContato).'%')
            ->orderBy('nome')->limit(8)->get();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periodo(): array
    {
        $d = Carbon::parse($this->cursor);

        return match ($this->visao) {
            // O mes comeca no domingo da primeira semana e acaba no sabado da ultima: e assim
            // que a folha de calendario fecha, sem meia semana solta.
            'mes' => [
                $d->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY),
                $d->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY),
            ],
            'dia' => [$d->copy()->startOfDay(), $d->copy()->endOfDay()],
            // A lista olha para a frente, e nao para o periodo: atraso nao e uma data.
            'lista' => [Carbon::createFromTimestamp(0), now()->addYears(5)],
            default => [
                $d->copy()->startOfWeek(Carbon::SUNDAY),
                $d->copy()->endOfWeek(Carbon::SATURDAY),
            ],
        };
    }

    private function rotulo(): string
    {
        $d = Carbon::parse($this->cursor);

        if ($this->visao === 'mes') {
            return self::MESES[$d->month].' de '.$d->year;
        }

        if ($this->visao === 'dia') {
            return self::DIAS_LONGOS[$d->dayOfWeek].', '.$d->day.' de '.self::MESES[$d->month];
        }

        if ($this->visao === 'lista') {
            return 'O que está por vir';
        }

        if ($this->visao === 'link') {
            return 'Link de agendamento';
        }

        $de = $d->copy()->startOfWeek(Carbon::SUNDAY);
        $ate = $d->copy()->endOfWeek(Carbon::SATURDAY);

        return $de->month === $ate->month
            ? $de->day.' a '.$ate->day.' de '.self::MESES[$de->month].' de '.$de->year
            : $de->day.' de '.self::MESES[$de->month].' a '.$ate->day.' de '.self::MESES[$ate->month];
    }

    /** As folhas do mes: linhas de sete dias, cada dia com o que tem marcado. */
    private function semanasDoMes(Carbon $de, Carbon $ate, Collection $itens): array
    {
        $mes = Carbon::parse($this->cursor)->month;
        $semanas = [];
        $linha = [];

        for ($d = $de->copy(); $d <= $ate; $d->addDay()) {
            $linha[] = [
                'data'   => $d->toDateString(),
                'numero' => $d->day,
                'noMes'  => $d->month === $mes,
                'hoje'   => $d->isToday(),
                'itens'  => $itens->filter(fn ($a) => $a->comeca_em->isSameDay($d))->values(),
            ];

            if (count($linha) === 7) {
                $semanas[] = $linha;
                $linha = [];
            }
        }

        return $semanas;
    }

    /** As colunas da grade de horas, com os blocos ja posicionados. */
    private function colunas(Carbon $de, Carbon $ate, Collection $itens): array
    {
        $colunas = [];

        for ($d = $de->copy(); $d <= $ate; $d->addDay()) {
            $doDia = $itens->filter(fn ($a) => $a->comeca_em->isSameDay($d))->values();

            $colunas[] = [
                'data'   => $d->toDateString(),
                'dia'    => self::DIAS[$d->dayOfWeek],
                'numero' => $d->day,
                'hoje'   => $d->isToday(),
                'blocos' => $this->blocos($doDia),
            ];
        }

        return $colunas;
    }

    /**
     * Onde cada bloco fica na coluna do dia.
     *
     * DUAS COISAS AO MESMO TEMPO PRECISAM DIVIDIR A LARGURA, senao uma esconde a outra e a
     * grade passa a mentir justamente na hora em que a resposta importa — a hora cheia. Os que
     * se cruzam viram um grupo, e dentro do grupo cada um pega a primeira faixa livre.
     *
     * Tudo em porcentagem do dia, para a altura da grade poder mudar sem refazer conta.
     */
    private function blocos(Collection $doDia): array
    {
        $grupos = [];
        $grupo = [];
        $faixas = [];
        $fimDoGrupo = null;

        foreach ($doDia->sortBy(fn ($a) => $a->comeca_em->timestamp) as $a) {
            $ini = $a->comeca_em;
            $dur = max(self::MINIMO_MIN, (int) ($a->duracao_min ?: 0));
            $fim = $ini->copy()->addMinutes($dur);

            if ($fimDoGrupo !== null && $ini >= $fimDoGrupo) {
                $grupos[] = ['itens' => $grupo, 'faixas' => count($faixas)];
                $grupo = [];
                $faixas = [];
                $fimDoGrupo = null;
            }

            $faixa = null;

            foreach ($faixas as $i => $fimDaFaixa) {
                if ($ini >= $fimDaFaixa) {
                    $faixa = $i;
                    break;
                }
            }

            if ($faixa === null) {
                $faixas[] = $fim;
                $faixa = count($faixas) - 1;
            } else {
                $faixas[$faixa] = $fim;
            }

            $grupo[] = ['ap' => $a, 'faixa' => $faixa, 'ini' => $ini, 'dur' => $dur];
            $fimDoGrupo = $fimDoGrupo === null || $fim > $fimDoGrupo ? $fim : $fimDoGrupo;
        }

        if ($grupo !== []) {
            $grupos[] = ['itens' => $grupo, 'faixas' => count($faixas)];
        }

        $blocos = [];

        foreach ($grupos as $g) {
            $larg = 100 / max(1, $g['faixas']);

            foreach ($g['itens'] as $it) {
                $minuto = $it['ini']->hour * 60 + $it['ini']->minute;

                $blocos[] = [
                    'ap'     => $it['ap'],
                    'topo'   => round($minuto / 1440 * 100, 4),
                    'altura' => round(min($it['dur'], 1440 - $minuto) / 1440 * 100, 4),
                    'esq'    => round($it['faixa'] * $larg, 4),
                    'larg'   => round($larg, 4),
                ];
            }
        }

        return $blocos;
    }

    /**
     * A visao lista, agrupada por urgencia.
     *
     * Responde outra pergunta que a grade nao responde: o que ficou para tras. Atraso nao e uma
     * data, e uma comparacao com agora — nao ha celula no calendario onde ele caiba.
     */
    private function porUrgencia(): array
    {
        $pendentes = $this->consulta()->pendentes()->orderBy('comeca_em')->get();

        $grupos = [
            'Atrasados' => $pendentes->filter(fn ($a) => $a->comeca_em->isPast()),
            'Hoje'      => $pendentes->filter(fn ($a) => $a->comeca_em->isToday() && $a->comeca_em->isFuture()),
            'Amanhã'    => $pendentes->filter(fn ($a) => $a->comeca_em->isTomorrow()),
            'Depois'    => $pendentes->filter(fn ($a) => $a->comeca_em->gt(now()->addDay()->endOfDay())),
        ];

        return array_filter($grupos, fn ($g) => $g->isNotEmpty());
    }

    private function agoraRedondo(): string
    {
        return now()->addHour()->startOfHour()->format('Y-m-d\TH:i');
    }
}
