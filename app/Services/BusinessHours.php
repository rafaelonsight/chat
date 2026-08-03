<?php

namespace App\Services;

use App\Models\BusinessHour;
use App\Models\BusinessHourException;
use App\Models\Channel;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Responde tres perguntas: estamos abertos agora, quando abrimos de novo, e
// quanto tempo util passou entre dois momentos. A terceira e a que conserta o
// relatorio: sem ela, mensagem das 23h respondida as 8h35 aparece como 9h35 de
// espera e pune a equipe pela noite.
class BusinessHours
{
    private const FUSO_PADRAO = 'America/Sao_Paulo';

    /** @var array<string, Collection> */
    private array $grades = [];

    public function __construct(private readonly Tenant $conta) {}

    public static function paraConta(?Tenant $conta): ?self
    {
        return $conta ? new self($conta) : null;
    }

    public function fuso(): string
    {
        return $this->conta->fuso_horario ?: self::FUSO_PADRAO;
    }

    // Sem grade configurada a feature fica inerte: sempre aberto, nenhuma
    // resposta automatica, relatorio inalterado.
    public function configurado(?Channel $canal = null, ?Team $equipe = null): bool
    {
        return $this->grade($canal, $equipe)->isNotEmpty();
    }

    public function abertoEm(Carbon $momento, ?Channel $canal = null, ?Team $equipe = null): bool
    {
        if (! $this->configurado($canal, $equipe)) {
            return true;
        }

        $local = $momento->copy()->setTimezone($this->fuso());

        foreach ($this->janelasEmTorno($local, $canal, $equipe) as [$inicio, $fim]) {
            if ($local->greaterThanOrEqualTo($inicio) && $local->lessThan($fim)) {
                return true;
            }
        }

        return false;
    }

    public function abertoAgora(?Channel $canal = null, ?Team $equipe = null): bool
    {
        return $this->abertoEm(Carbon::now(), $canal, $equipe);
    }

    public function proximaAbertura(Carbon $de, ?Channel $canal = null, ?Team $equipe = null): ?Carbon
    {
        if (! $this->configurado($canal, $equipe)) {
            return null;
        }

        $local = $de->copy()->setTimezone($this->fuso());

        // 21 dias cobrem feriados longos e semanas inteiras fechadas
        for ($dias = 0; $dias <= 21; $dias++) {
            $dia = $local->copy()->addDays($dias)->startOfDay();

            foreach ($this->janelasDoDia($dia, $canal, $equipe) as [$inicio, $fim]) {
                if ($inicio->greaterThan($local)) {
                    return $inicio;
                }

                // dentro de uma janela: "proxima abertura" e agora
                if ($local->greaterThanOrEqualTo($inicio) && $local->lessThan($fim)) {
                    return $local;
                }
            }
        }

        return null;
    }

    public function proximaAberturaLegivel(?Carbon $de = null, ?Channel $canal = null, ?Team $equipe = null): ?string
    {
        $de = ($de ?? Carbon::now())->copy()->setTimezone($this->fuso());
        $proxima = $this->proximaAbertura($de, $canal, $equipe);

        if (! $proxima) {
            return null;
        }

        $hora = $proxima->format('H:i');

        return match (true) {
            $proxima->isSameDay($de)                        => "hoje às {$hora}",
            $proxima->isSameDay($de->copy()->addDay())      => "amanhã às {$hora}",
            default => $proxima->locale('pt_BR')->translatedFormat('\\n\\a \\1\\ó\\r\\i\\a')
                ? BusinessHour::DIAS[(int) $proxima->dayOfWeek].' às '.$hora
                : "em {$proxima->format('d/m')} às {$hora}",
        };
    }

    public function minutosUteisEntre(Carbon $de, Carbon $ate, ?Channel $canal = null, ?Team $equipe = null): int
    {
        if ($ate->lessThanOrEqualTo($de)) {
            return 0;
        }

        if (! $this->configurado($canal, $equipe)) {
            return (int) round($de->diffInSeconds($ate) / 60);
        }

        $inicio = $de->copy()->setTimezone($this->fuso());
        $fim = $ate->copy()->setTimezone($this->fuso());

        $segundos = 0;
        $dia = $inicio->copy()->startOfDay()->subDay(); // -1 pega janela que cruzou a meia-noite
        $limite = $fim->copy()->startOfDay()->addDay();

        while ($dia->lessThanOrEqualTo($limite)) {
            foreach ($this->janelasDoDia($dia, $canal, $equipe) as [$jIni, $jFim]) {
                $a = $jIni->greaterThan($inicio) ? $jIni : $inicio;
                $b = $jFim->lessThan($fim) ? $jFim : $fim;

                if ($b->greaterThan($a)) {
                    $segundos += $a->diffInSeconds($b);
                }
            }

            $dia->addDay();
        }

        return (int) round($segundos / 60);
    }

    // ----------------------------------------------------------------- interno

    private function grade(?Channel $canal, ?Team $equipe = null): Collection
    {
        $chave = sprintf('e%s|c%s', $equipe?->id ?? '-', $canal?->id ?? '-');

        if (isset($this->grades[$chave])) {
            return $this->grades[$chave];
        }

        // Precedencia equipe > canal > conta. A equipe e o escopo mais especifico
        // porque o mesmo numero atende Suporte 24h e Financeiro comercial.
        if ($equipe) {
            $daEquipe = BusinessHour::where('team_id', $equipe->id)->get();

            if ($daEquipe->isNotEmpty()) {
                return $this->grades[$chave] = $daEquipe->keyBy('dia_semana');
            }
        }

        if ($canal) {
            $doCanal = BusinessHour::where('channel_id', $canal->id)->get();

            if ($doCanal->isNotEmpty()) {
                return $this->grades[$chave] = $doCanal->keyBy('dia_semana');
            }
        }

        // whereNull nos DOIS: so channel_id deixaria as linhas de equipe entrarem
        // na grade da conta e a conta passaria a herdar horario de equipe.
        return $this->grades[$chave] = BusinessHour::whereNull('channel_id')
            ->whereNull('team_id')
            ->get()
            ->keyBy('dia_semana');
    }

    // Intervalos do dia, com excecao de data tendo prioridade sobre a grade.
    private function intervalosDo(Carbon $dia, ?Channel $canal, ?Team $equipe = null): array
    {
        $excecao = BusinessHourException::where('data', $dia->toDateString())->first();

        if ($excecao) {
            return $excecao->fechado ? [] : ($excecao->intervalos ?? []);
        }

        $linha = $this->grade($canal, $equipe)->get((int) $dia->dayOfWeek);

        if (! $linha || ! $linha->ativo) {
            return [];
        }

        return $linha->intervalos ?? [];
    }

    // Janelas absolutas do dia. Fim menor ou igual ao inicio significa que a
    // janela atravessa a meia-noite (plantao 22h->02h).
    private function janelasDoDia(Carbon $dia, ?Channel $canal, ?Team $equipe = null): array
    {
        $base = $dia->copy()->setTimezone($this->fuso())->startOfDay();
        $janelas = [];

        foreach ($this->intervalosDo($base, $canal, $equipe) as $intervalo) {
            $ini = $intervalo['inicio'] ?? null;
            $fim = $intervalo['fim'] ?? null;

            if (! $ini || ! $fim) {
                continue;
            }

            $a = $base->copy()->setTimeFromTimeString($ini);
            $b = $base->copy()->setTimeFromTimeString($fim);

            if ($b->lessThanOrEqualTo($a)) {
                $b->addDay();
            }

            $janelas[] = [$a, $b];
        }

        return $janelas;
    }

    // Para saber se um momento esta aberto, o dia anterior importa: uma janela
    // que cruzou a meia-noite ainda pode estar valendo.
    private function janelasEmTorno(Carbon $local, ?Channel $canal, ?Team $equipe = null): array
    {
        return array_merge(
            $this->janelasDoDia($local->copy()->subDay(), $canal, $equipe),
            $this->janelasDoDia($local, $canal, $equipe),
        );
    }
}
