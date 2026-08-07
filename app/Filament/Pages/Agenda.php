<?php

namespace App\Filament\Pages;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\User;
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
    ];

    public const MESES = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    public const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    public const DIAS_LONGOS = [
        'domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
        'quinta-feira', 'sexta-feira', 'sábado',
    ];

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
        $this->reset(['editando', 'titulo', 'descricao', 'contact_id', 'buscaContato']);
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
            Appointment::visivelPara(auth()->user())->findOrFail($this->editando)->update($dados);
        } else {
            Appointment::create($dados + [
                'tenant_id'  => auth()->user()->tenant_id,
                'criado_por' => auth()->id(),
            ]);
        }

        // O calendario anda ate onde a coisa foi marcada: salvar e nao ver o que salvou parece
        // que nao salvou.
        $this->cursor = Carbon::parse($this->quando)->toDateString();

        $this->formAberto = false;
        $this->editando = null;
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
    }

    public function concluir(int $id): void
    {
        $a = Appointment::visivelPara(auth()->user())->find($id);

        $a?->update(['concluido_em' => $a->concluido() ? null : now()]);
    }

    public function excluir(int $id): void
    {
        Appointment::visivelPara(auth()->user())->find($id)?->delete();

        $this->formAberto = false;
        $this->editando = null;
    }

    // ------------------------------------------------------------ dados

    public function getViewData(): array
    {
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
        ];
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
