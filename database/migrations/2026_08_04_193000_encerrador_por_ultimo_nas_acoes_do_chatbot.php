<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repara grupos onde uma acao ficou DEPOIS de Transferir/Concluir.
 *
 * O motor encerra o fluxo no encerrador: tudo o que vem depois nunca roda. Foi assim
 * que uma etiqueta configurada no painel nunca chegou ao contato — a acao existia, o
 * cartao mostrava, e ela era morta. Move o encerrador para o fim do grupo, que e o
 * unico arranjo em que todas as acoes rodam.
 *
 * Os tipos estao escritos a mao de proposito: migracao roda em qualquer versao futura
 * do codigo, e nao pode depender de constante que talvez mude de nome.
 */
return new class extends Migration
{
    private const ENCERRAM = ['transferir', 'concluir'];

    public function up(): void
    {
        $passos = DB::table('chatbot_actions')->distinct()->pluck('step_id');

        foreach ($passos as $stepId) {
            $acoes = DB::table('chatbot_actions')
                ->where('step_id', $stepId)
                ->orderBy('ordem')
                ->orderBy('id')
                ->get(['id', 'tipo', 'ordem']);

            $normais = $acoes->reject(fn ($a) => in_array($a->tipo, self::ENCERRAM, true));
            $finais = $acoes->filter(fn ($a) => in_array($a->tipo, self::ENCERRAM, true));

            $ordem = 1;

            foreach ($normais->concat($finais) as $acao) {
                if ((int) $acao->ordem !== $ordem) {
                    DB::table('chatbot_actions')->where('id', $acao->id)->update(['ordem' => $ordem]);
                }

                $ordem++;
            }
        }
    }

    public function down(): void
    {
        // Sem volta de proposito: a ordem anterior era justamente a que nao funcionava.
        // Nao ha valor em restaurar acao morta.
    }
};
