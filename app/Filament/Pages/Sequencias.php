<?php

namespace App\Filament\Pages;

use App\Models\Channel;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Sequencias extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Aplicações';

    protected static ?string $navigationLabel = 'Sequências';

    protected static ?string $title = 'Sequências';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'sequencias';

    protected string $view = 'filament.pages.sequencias';

    public ?int $editando = null;

    public bool $formAberto = false;

    public string $nome = '';

    public ?int $channel_id = null;

    public string $gatilho = Sequence::PRIMEIRA_CONVERSA;

    public bool $parar_ao_responder = true;

    public int $sem_resposta_horas = 24;

    public int $hora_inicio = 9;

    public int $hora_fim = 20;

    /** @var array<int, array{atraso_horas: int, corpo: string}> */
    public array $passos = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function mount(): void
    {
        $this->channel_id = Channel::query()->value('id');
    }

    public function nova(): void
    {
        $this->reset(['editando', 'nome', 'gatilho', 'passos']);
        $this->gatilho = Sequence::PRIMEIRA_CONVERSA;
        $this->parar_ao_responder = true;
        $this->sem_resposta_horas = 24;
        $this->hora_inicio = 9;
        $this->hora_fim = 20;
        $this->channel_id = Channel::query()->value('id');
        $this->passos = [['atraso_horas' => 24, 'corpo' => '']];
        $this->formAberto = true;
    }

    public function editar(int $id): void
    {
        $s = Sequence::with('steps')->findOrFail($id);

        $this->editando = $s->id;
        $this->nome = $s->nome;
        $this->channel_id = $s->channel_id;
        $this->gatilho = $s->gatilho;
        $this->parar_ao_responder = $s->parar_ao_responder;
        $this->sem_resposta_horas = $s->sem_resposta_horas;
        $this->hora_inicio = $s->hora_inicio;
        $this->hora_fim = $s->hora_fim;
        $this->passos = $s->steps->map(fn ($p) => [
            'atraso_horas' => $p->atraso_horas,
            'corpo'        => $p->corpo,
        ])->values()->all();
        $this->formAberto = true;
    }

    public function adicionarPasso(): void
    {
        $this->passos[] = ['atraso_horas' => 24, 'corpo' => ''];
    }

    public function removerPasso(int $i): void
    {
        unset($this->passos[$i]);
        $this->passos = array_values($this->passos);
    }

    public function salvar(): void
    {
        $this->validate([
            'nome'               => 'required|string|max:80',
            'channel_id'         => 'required|integer',
            'gatilho'            => 'required|in:'.implode(',', array_keys(Sequence::GATILHOS)),
            'sem_resposta_horas' => 'required|integer|min:1|max:720',
            'hora_inicio'        => 'required|integer|min:0|max:23',
            'hora_fim'           => 'required|integer|min:1|max:23|gt:hora_inicio',
            'passos'             => 'required|array|min:1',
            'passos.*.corpo'     => 'required|string|max:1000',
            'passos.*.atraso_horas' => 'required|integer|min:0|max:8760',
        ], [], [
            'passos'          => 'mensagens',
            'passos.*.corpo'  => 'texto da mensagem',
            'hora_fim'        => 'hora final',
        ]);

        $dados = [
            'nome'               => trim($this->nome),
            'channel_id'         => $this->channel_id,
            'gatilho'            => $this->gatilho,
            'parar_ao_responder' => $this->parar_ao_responder,
            'sem_resposta_horas' => $this->sem_resposta_horas,
            'hora_inicio'        => $this->hora_inicio,
            'hora_fim'           => $this->hora_fim,
        ];

        $sequencia = $this->editando
            ? tap(Sequence::findOrFail($this->editando))->update($dados)
            : Sequence::create($dados + ['tenant_id' => auth()->user()->tenant_id, 'ativa' => false]);

        // Reescreve os passos inteiros. Casar linha a linha com o que existia daria margem a
        // uma jornada ficar com metade dos passos velhos e metade dos novos — e quem esta no
        // meio dela receberia uma mistura que ninguem escreveu.
        $sequencia->steps()->delete();

        foreach (array_values($this->passos) as $i => $p) {
            SequenceStep::create([
                'sequence_id'  => $sequencia->id,
                'ordem'        => $i + 1,
                'atraso_horas' => (int) $p['atraso_horas'],
                'corpo'        => trim($p['corpo']),
            ]);
        }

        $this->formAberto = false;
        $this->editando = null;

        Notification::make()->title('Sequência salva')->success()->send();
    }

    public function alternarAtiva(int $id): void
    {
        $s = Sequence::with('steps')->findOrFail($id);

        if (! $s->ativa && $s->steps->isEmpty()) {
            $this->addError('sequencia', 'Sequência sem mensagem nenhuma não liga.');

            return;
        }

        $s->update(['ativa' => ! $s->ativa]);
    }

    public function excluir(int $id): void
    {
        $s = Sequence::findOrFail($id);

        if ($s->enrollments()->where('status', SequenceEnrollment::ATIVA)->exists()) {
            $this->addError('sequencia', 'Há gente no meio desta jornada. Desligue e espere terminar, ou pare as inscrições.');

            return;
        }

        $s->delete();
    }

    public function pararTodas(int $id): void
    {
        Sequence::findOrFail($id)->enrollments()
            ->where('status', SequenceEnrollment::ATIVA)
            ->get()
            ->each(fn ($i) => $i->parar('a jornada foi interrompida à mão'));
    }

    public function getViewData(): array
    {
        return [
            'sequencias' => Sequence::with(['channel', 'steps'])
                ->withCount([
                    'enrollments as ativas_count'    => fn ($q) => $q->where('status', SequenceEnrollment::ATIVA),
                    'enrollments as concluidas_count' => fn ($q) => $q->where('status', SequenceEnrollment::CONCLUIDA),
                    'enrollments as paradas_count'   => fn ($q) => $q->where('status', SequenceEnrollment::PARADA),
                ])
                ->latest('id')->get(),
            'canais'   => Channel::orderBy('nome')->get(),
            'gatilhos' => Sequence::GATILHOS,
        ];
    }
}
