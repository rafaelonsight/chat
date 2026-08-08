<?php

namespace App\Services\Agendamento;

use App\Models\Appointment;
use App\Models\BookingPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Quais horarios ainda estao livres numa pagina de reserva.
 *
 * A REGRA DE OURO E NAO OFERECER O QUE NAO EXISTE. Uma vaga mostrada e um compromisso que o
 * cliente considera fechado; se ela sumir na confirmacao, o estrago e maior do que se ela
 * nunca tivesse aparecido. Entao aqui tudo corta para menos: fora da faixa, cedo demais,
 * encostado em outro compromisso, dia cheio — nao aparece.
 *
 * LEMBRETE NAO OCUPA HORARIO. Ele nao tem duracao e e um bilhete para si mesmo; se bloqueasse
 * a agenda, anotar "ligar para o fulano" fecharia uma vaga de visita sem ninguem perceber.
 */
class Vagas
{
    public function __construct(private readonly BookingPage $pagina) {}

    /**
     * Os dias com vaga, do mais proximo em diante.
     *
     * @return array<string, list<Carbon>> 'Y-m-d' => horarios livres
     */
    public function porDia(?Carbon $de = null, ?Carbon $ate = null): array
    {
        if (! $this->pagina->ativa || $this->pagina->duracao_min < 5) {
            return [];
        }

        $de = ($de?->copy() ?? now())->startOfDay();
        $hoje = now()->startOfDay();

        // Passado nao se oferece, nem que peçam.
        if ($de->lt($hoje)) {
            $de = $hoje;
        }

        $teto = now()->copy()->addDays(max(1, $this->pagina->janela_dias))->endOfDay();
        $ate = $ate?->copy()->endOfDay() ?? $teto;

        if ($ate->gt($teto)) {
            $ate = $teto;
        }

        if ($de->gt($ate)) {
            return [];
        }

        $ocupado = $this->ocupado($de, $ate);
        $saida = [];

        for ($d = $de->copy(); $d->lte($ate); $d->addDay()) {
            $livres = $this->doDia($d, $ocupado);

            if ($livres !== []) {
                $saida[$d->toDateString()] = $livres;
            }
        }

        return $saida;
    }

    /** @return list<Carbon> */
    public function doDia(Carbon $dia, ?Collection $ocupado = null): array
    {
        $faixas = $this->pagina->faixasDe($dia->dayOfWeek);

        if ($faixas === []) {
            return [];
        }

        $ocupado ??= $this->ocupado($dia->copy()->startOfDay(), $dia->copy()->endOfDay());

        $doDia = $ocupado->filter(fn ($o) => $o['inicio']->isSameDay($dia));

        // Teto do dia: o link nao pode lotar uma quarta inteira e deixar a pessoa sem almoco.
        if ($this->pagina->limite_dia) {
            $reservados = $doDia->where('reservado', true)->count();

            if ($reservados >= $this->pagina->limite_dia) {
                return [];
            }
        }

        $duracao = $this->pagina->duracao_min;
        $passo = max(5, $duracao + $this->pagina->intervalo_min);
        $cedo = now()->copy()->addHours($this->pagina->antecedencia_horas);
        $livres = [];

        foreach ($faixas as $faixa) {
            $inicio = $dia->copy()->setTimeFromTimeString($faixa['de']);
            $fimDaFaixa = $dia->copy()->setTimeFromTimeString($faixa['ate']);

            for ($s = $inicio->copy(); $s->copy()->addMinutes($duracao)->lte($fimDaFaixa); $s->addMinutes($passo)) {
                if ($s->lt($cedo)) {
                    continue;
                }

                if ($this->colide($s, $duracao, $doDia)) {
                    continue;
                }

                $livres[] = $s->copy();
            }
        }

        return $livres;
    }

    /** A vaga ainda esta de pe? Perguntado de novo na hora de gravar. */
    public function estaLivre(Carbon $quando): bool
    {
        foreach ($this->doDia($quando->copy()->startOfDay()) as $vaga) {
            if ($vaga->equalTo($quando)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O que ja esta na agenda de quem atende.
     *
     * A folga entra dos DOIS lados do que ja existe: quem sai de uma visita as 15h com quinze
     * minutos de folga nao pode ter a proxima as 15h01 nem a anterior terminando as 14h59.
     */
    private function ocupado(Carbon $de, Carbon $ate): Collection
    {
        $folga = $this->pagina->intervalo_min;

        return Appointment::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $this->pagina->tenant_id)
            ->where('user_id', $this->pagina->user_id)
            ->where('tipo', Appointment::COMPROMISSO)
            ->whereBetween('comeca_em', [$de->copy()->subDay(), $ate->copy()->addDay()])
            ->get(['comeca_em', 'duracao_min', 'booking_page_id'])
            ->map(fn (Appointment $a) => [
                'inicio'    => $a->comeca_em->copy()->subMinutes($folga),
                'fim'       => $a->comeca_em->copy()->addMinutes(($a->duracao_min ?: $this->pagina->duracao_min) + $folga),
                'reservado' => $a->booking_page_id !== null,
            ]);
    }

    private function colide(Carbon $inicio, int $duracao, Collection $ocupado): bool
    {
        $fim = $inicio->copy()->addMinutes($duracao);

        foreach ($ocupado as $o) {
            if ($inicio->lt($o['fim']) && $fim->gt($o['inicio'])) {
                return true;
            }
        }

        return false;
    }
}
