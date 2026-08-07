<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use Illuminate\Support\Carbon;

/**
 * Quem entra numa sequencia, quando recebe o proximo passo, e o que faz parar.
 *
 * O IRMAO DESTE ARQUIVO E O Disparador, das campanhas, e as exclusoes sao as mesmas: quem
 * pediu para sair, bloqueado, arquivado e grupo. A diferenca esta em PARAR NO MEIO — campanha
 * e um disparo so, sequencia continua por dias, e nesses dias o mundo muda.
 */
class Cadenciador
{
    public function __construct(private readonly Disparador $disparador) {}

    /**
     * Inscreve o contato nas sequencias ativas deste gatilho.
     *
     * @return int quantas inscricoes nasceram
     */
    public function inscrever(string $gatilho, Contact $contato, ?Conversation $conversa = null): int
    {
        if (! $this->podeReceber($contato)) {
            return 0;
        }

        $nascidas = 0;

        $sequencias = Sequence::query()
            ->where('tenant_id', $contato->tenant_id)
            ->where('gatilho', $gatilho)
            ->where('ativa', true)
            ->with('steps')
            ->get();

        foreach ($sequencias as $sequencia) {
            $primeiro = $sequencia->steps->first();

            // Sequencia sem passo nenhum nao inscreve ninguem: seria uma jornada vazia que
            // fica "ativa" para sempre e aparece nos numeros como se estivesse fazendo algo.
            if (! $primeiro) {
                continue;
            }

            // Ja esta dentro: nao inscreve de novo. O indice unico parcial tambem barra, mas
            // conferir aqui evita depender de excecao para o caminho normal.
            $jaEsta = SequenceEnrollment::where('sequence_id', $sequencia->id)
                ->where('contact_id', $contato->id)
                ->where('status', SequenceEnrollment::ATIVA)
                ->exists();

            if ($jaEsta) {
                continue;
            }

            SequenceEnrollment::create([
                'sequence_id'     => $sequencia->id,
                'contact_id'      => $contato->id,
                'conversation_id' => $conversa?->id,
                'proximo_passo'   => $primeiro->ordem,
                'proximo_em'      => $this->quando($sequencia, $primeiro->atraso_horas),
            ]);

            $nascidas++;
        }

        return $nascidas;
    }

    /**
     * O cliente falou. Para tudo que estava em andamento para ele.
     *
     * E a regra central do modulo. Sem ela a sequencia vira perseguicao: a pessoa responde,
     * alguem atende, e a maquina continua mandando "notou que voce nao respondeu?" no dia
     * seguinte.
     */
    public function clienteRespondeu(Contact $contato): int
    {
        $paradas = 0;

        SequenceEnrollment::where('contact_id', $contato->id)
            ->where('status', SequenceEnrollment::ATIVA)
            ->with('sequence')
            ->get()
            ->each(function (SequenceEnrollment $inscricao) use (&$paradas) {
                if (! $inscricao->sequence?->parar_ao_responder) {
                    return;
                }

                $inscricao->parar('o cliente respondeu');
                $paradas++;
            });

        return $paradas;
    }

    /** As mesmas exclusoes da campanha: quem pediu para sair, bloqueado, arquivado e grupo. */
    public function podeReceber(?Contact $contato): bool
    {
        return $contato !== null
            && ! $contato->saiuDaLista()
            && $contato->bloqueado_em === null
            && $contato->arquivado_em === null
            && $contato->tipo !== 'grupo';
    }

    /**
     * Quando o passo deve sair, ja respeitando a janela de horario.
     *
     * Usa a mesma regra da campanha, e de proposito: duas ideias de "horario permitido" no
     * mesmo produto viram dois comportamentos e uma reclamacao.
     */
    public function quando(Sequence $sequencia, int $atrasoHoras, ?Carbon $de = null): Carbon
    {
        $momento = ($de ?? now())->copy()->addHours(max(0, $atrasoHoras));

        // A janela da campanha e da sequencia tem os mesmos campos; o Disparador so le.
        $falsa = new \App\Models\Campaign([
            'hora_inicio' => $sequencia->hora_inicio,
            'hora_fim'    => $sequencia->hora_fim,
        ]);

        return $this->disparador->dentroDaJanela($falsa, $momento);
    }
}
