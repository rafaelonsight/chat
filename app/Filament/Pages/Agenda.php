<?php

namespace App\Filament\Pages;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Agenda: compromisso com o cliente, e lembrete pessoal.
 *
 * A TELA E ORGANIZADA POR URGENCIA, e nao por calendario. Mes inteiro em grade e bonito e
 * responde a pergunta errada: quem abre a agenda de manha quer saber o que ESTA ATRASADO e o
 * que e HOJE — nao o que tem no dia 23.
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

    public bool $mostrarConcluidos = false;

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
        $this->user_id = auth()->id();
        $this->quando = now()->addHour()->startOfHour()->format('Y-m-d\TH:i');
    }

    // ------------------------------------------------------------------ form

    public function novo(): void
    {
        $this->reset(['editando', 'titulo', 'descricao', 'contact_id', 'buscaContato']);
        $this->tipo = Appointment::COMPROMISSO;
        $this->duracao_min = 60;
        $this->user_id = auth()->id();
        $this->quando = now()->addHour()->startOfHour()->format('Y-m-d\TH:i');
        $this->formAberto = true;
    }

    public function editar(int $id): void
    {
        $a = Appointment::visivelPara(auth()->user())->findOrFail($id);

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

    // ---------------------------------------------------------------- acoes

    public function concluir(int $id): void
    {
        $a = Appointment::visivelPara(auth()->user())->find($id);

        $a?->update(['concluido_em' => $a->concluido() ? null : now()]);
    }

    public function excluir(int $id): void
    {
        Appointment::visivelPara(auth()->user())->find($id)?->delete();
    }

    // ---------------------------------------------------------------- dados

    public function getViewData(): array
    {
        $base = fn () => Appointment::query()
            ->visivelPara(auth()->user())
            ->with(['user', 'contact']);

        $pendentes = $base()->pendentes()->orderBy('comeca_em')->get();

        // Agrupado por urgencia, nao por calendario: quem abre de manha quer saber o que esta
        // atrasado e o que e hoje.
        $grupos = [
            'Atrasados' => $pendentes->filter(fn ($a) => $a->comeca_em->isPast()),
            'Hoje'      => $pendentes->filter(fn ($a) => $a->comeca_em->isToday() && $a->comeca_em->isFuture()),
            'Amanhã'    => $pendentes->filter(fn ($a) => $a->comeca_em->isTomorrow()),
            'Depois'    => $pendentes->filter(fn ($a) => $a->comeca_em->gt(now()->addDay()->endOfDay())),
        ];

        $candidatos = mb_strlen(trim($this->buscaContato)) >= 2 && ! $this->contact_id
            ? Contact::ativos()->where('nome', 'ilike', '%'.trim($this->buscaContato).'%')
                ->orderBy('nome')->limit(8)->get()
            : collect();

        return [
            'grupos'      => array_filter($grupos, fn ($g) => $g->isNotEmpty()),
            'concluidos'  => $this->mostrarConcluidos
                ? $base()->whereNotNull('concluido_em')->latest('comeca_em')->limit(30)->get()
                : collect(),
            'pessoas'     => User::orderBy('name')->get(),
            'candidatos'  => $candidatos,
            'temAlgo'     => $pendentes->isNotEmpty(),
        ];
    }
}
