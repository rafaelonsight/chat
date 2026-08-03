<?php

namespace App\Filament\Pages;

use App\Models\BusinessHour;
use App\Models\BusinessHourException;
use App\Models\Team;
use App\Models\Tenant;
use App\Services\BusinessHours;
use App\Support\Marcadores;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class HorarioAtendimento extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Conta';

    protected static ?string $navigationLabel = 'Horário de Atendimento';

    protected static ?string $title = 'Horário de atendimento';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'horario-atendimento';

    protected string $view = 'filament.pages.horario-atendimento';

    /** @var array<int, array{ativo: bool, inicio: string, almoco_inicio: string, almoco_fim: string, fim: string}> */
    public array $dias = [];

    // 'conta' ou 'equipe:{id}'. O mesmo numero atende Suporte 24h e Financeiro
    // comercial, entao a grade precisa de um eixo por equipe.
    public string $escopo = 'conta';

    public string $fuso_horario = 'America/Sao_Paulo';

    public bool $resposta_ativa = false;

    public string $resposta_texto = '';

    // excecao nova
    public string $ex_data = '';

    public bool $ex_fechado = true;

    public string $ex_inicio = '08:30';

    public string $ex_fim = '12:00';

    public string $ex_descricao = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function mount(): void
    {
        $conta = $this->conta();

        $this->fuso_horario = (string) ($conta?->fuso_horario ?: 'America/Sao_Paulo');
        $this->resposta_ativa = (bool) $conta?->resposta_automatica_ativa;
        $this->resposta_texto = (string) ($conta?->resposta_automatica_texto
            ?: 'Olá {{nome}}, no momento estamos fora do horário de atendimento. Voltamos {{proxima_abertura}}.');

        $this->carregarGrade();
    }

    // ------------------------------------------------------------------- escopo

    public function trocarEscopo(string $escopo): void
    {
        $this->escopo = $escopo;
        $this->resetErrorBag();
        $this->carregarGrade();
    }

    public function equipeDoEscopo(): ?Team
    {
        if (! str_starts_with($this->escopo, 'equipe:')) {
            return null;
        }

        return Team::find((int) substr($this->escopo, 7));
    }

    /** As chaves que identificam uma linha no escopo atual. */
    private function chavesDoEscopo(): array
    {
        $equipe = $this->equipeDoEscopo();

        return ['channel_id' => null, 'team_id' => $equipe?->id];
    }

    private function linhasDoEscopo()
    {
        $equipe = $this->equipeDoEscopo();

        return $equipe
            ? BusinessHour::where('team_id', $equipe->id)->get()
            : BusinessHour::whereNull('channel_id')->whereNull('team_id')->get();
    }

    // Equipe sem grade propria HERDA a da conta. Sem dizer isso na tela, o admin
    // ve um formulario preenchido e nao tem como saber se vale ou nao.
    public function herdando(): bool
    {
        return $this->equipeDoEscopo() !== null && $this->linhasDoEscopo()->isEmpty();
    }

    public function limparEscopo(): void
    {
        $equipe = $this->equipeDoEscopo();

        if (! $equipe) {
            return;
        }

        BusinessHour::where('team_id', $equipe->id)->delete();
        $this->carregarGrade();

        Notification::make()->success()->title($equipe->nome.' volta a herdar o horário da conta')->send();
    }

    private function carregarGrade(): void
    {
        $grade = $this->linhasDoEscopo()->keyBy('dia_semana');

        // Equipe sem grade propria mostra a da conta como ponto de partida: e o
        // que ela esta usando de fato hoje.
        if ($grade->isEmpty() && $this->equipeDoEscopo()) {
            $grade = BusinessHour::whereNull('channel_id')->whereNull('team_id')->get()->keyBy('dia_semana');
        }

        foreach (array_keys(BusinessHour::DIAS) as $dia) {
            $linha = $grade->get($dia);
            $intervalos = $linha->intervalos ?? [];

            // A tela mostra quatro campos porque cobre o caso real do provedor.
            // O banco guarda intervalos, entao horarios fora desse molde
            // (plantao, turno triplo) sobrevivem mesmo sem UI para eles.
            $this->dias[$dia] = [
                'ativo'         => $linha ? (bool) $linha->ativo : $dia !== 0,
                'inicio'        => $intervalos[0]['inicio'] ?? '08:30',
                'almoco_inicio' => $intervalos[0]['fim'] ?? '12:00',
                'almoco_fim'    => $intervalos[1]['inicio'] ?? '13:00',
                'fim'           => $intervalos[1]['fim'] ?? ($intervalos[0]['fim'] ?? '18:00'),
            ];
        }
    }

    private function conta(): ?Tenant
    {
        $id = auth()->user()?->tenant_id;

        return $id ? Tenant::find($id) : null;
    }

    public function salvar(): void
    {
        $this->validate([
            'fuso_horario'   => 'required|timezone',
            'resposta_texto' => 'required_if:resposta_ativa,true|nullable|string|max:1000',
        ], [], ['resposta_texto' => 'mensagem da resposta automática']);

        // A grade precisa fazer sentido: fora de ordem, a logica de "estamos
        // abertos" quebra em silencio e a resposta automatica dispara errado.
        foreach ($this->dias as $dia => $d) {
            if (! $d['ativo']) {
                continue;
            }

            $nome = BusinessHour::DIAS[$dia];

            // Almoco de duracao zero significa "dia sem pausa" e a propria tela
            // diz isso ao usuario. A regra antiga exigia os quatro horarios
            // estritamente crescentes, entao o dia sem pausa era recusado: a tela
            // prometia algo que o codigo nao aceitava.
            $ordenado = $d['inicio'] < $d['almoco_inicio']
                && $d['almoco_inicio'] <= $d['almoco_fim']
                && $d['almoco_fim'] < $d['fim'];

            if (! $ordenado) {
                $this->addError(
                    "dias.{$dia}.inicio",
                    "{$nome}: os horários precisam estar em ordem crescente (o almoço pode ter início e fim iguais para um dia sem pausa)."
                );

                return;
            }
        }

        $conta = $this->conta();

        if (! $conta) {
            return;
        }

        $conta->update([
            'fuso_horario'              => $this->fuso_horario,
            'resposta_automatica_ativa' => $this->resposta_ativa,
            'resposta_automatica_texto' => trim($this->resposta_texto) ?: null,
        ]);

        foreach ($this->dias as $dia => $d) {
            $intervalos = [];

            if ($d['ativo']) {
                // almoco de duracao zero = dia sem pausa, um intervalo unico
                if ($d['almoco_inicio'] === $d['almoco_fim']) {
                    $intervalos = [['inicio' => $d['inicio'], 'fim' => $d['fim']]];
                } else {
                    $intervalos = [
                        ['inicio' => $d['inicio'], 'fim' => $d['almoco_inicio']],
                        ['inicio' => $d['almoco_fim'], 'fim' => $d['fim']],
                    ];
                }
            }

            BusinessHour::updateOrCreate(
                array_merge($this->chavesDoEscopo(), ['dia_semana' => $dia]),
                ['ativo' => $d['ativo'], 'intervalos' => $intervalos],
            );
        }

        $onde = $this->equipeDoEscopo()?->nome ?? 'conta';
        Notification::make()->success()->title("Horário salvo ({$onde})")->send();
    }

    public function adicionarExcecao(): void
    {
        $this->validate([
            'ex_data'      => 'required|date',
            'ex_descricao' => 'nullable|string|max:120',
            'ex_inicio'    => 'required_if:ex_fechado,false|nullable|string',
            'ex_fim'       => 'required_if:ex_fechado,false|nullable|string',
        ], [], ['ex_data' => 'data']);

        if (! $this->ex_fechado && $this->ex_fim <= $this->ex_inicio) {
            $this->addError('ex_fim', 'O fim tem que ser depois do início.');

            return;
        }

        BusinessHourException::updateOrCreate(
            ['data' => $this->ex_data],
            [
                'fechado'    => $this->ex_fechado,
                'intervalos' => $this->ex_fechado ? null : [['inicio' => $this->ex_inicio, 'fim' => $this->ex_fim]],
                'descricao'  => trim($this->ex_descricao) ?: null,
            ],
        );

        $this->reset(['ex_data', 'ex_descricao']);
        Notification::make()->success()->title('Exceção salva')->send();
    }

    public function removerExcecao(int $id): void
    {
        BusinessHourException::find($id)?->delete();
    }

    public function getViewData(): array
    {
        $conta = $this->conta();
        $horas = $conta ? new BusinessHours($conta) : null;

        $equipe = $this->equipeDoEscopo();

        return [
            'nomesDias'   => BusinessHour::DIAS,
            'equipes'     => Team::ativas()->orderBy('nome')->get(),
            'herdando'    => $this->herdando(),
            'excecoes'    => BusinessHourException::orderBy('data')->get(),
            'marcadores'  => Marcadores::DISPONIVEIS,
            'abertoAgora' => $horas?->configurado(null, $equipe) ? $horas->abertoAgora(null, $equipe) : null,
            'proxima'     => $horas?->proximaAberturaLegivel(null, null, $equipe),
            'fusos'       => [
                'America/Sao_Paulo'  => 'Brasília (GMT-3)',
                'America/Manaus'     => 'Manaus (GMT-4)',
                'America/Rio_Branco' => 'Rio Branco (GMT-5)',
                'America/Fortaleza'  => 'Fortaleza (GMT-3)',
                'America/Belem'      => 'Belém (GMT-3)',
                'America/Recife'     => 'Recife (GMT-3)',
                'America/Cuiaba'     => 'Cuiabá (GMT-4)',
                'America/Noronha'    => 'Fernando de Noronha (GMT-2)',
            ],
            'agora' => Carbon::now($this->fuso_horario)->format('d/m/Y H:i'),
        ];
    }
}
