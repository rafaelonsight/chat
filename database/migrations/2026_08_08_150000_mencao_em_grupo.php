<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Fulano te mencionou no grupo."
 *
 * Num grupo movimentado, a unica mensagem que e SUA e aquela em que te chamam. Sem separar
 * isso, o atendente le duzentas mensagens para achar uma — ou desiste e nao le nenhuma, que e
 * o que acontece de verdade.
 *
 * A marca fica nos DOIS lugares de proposito: na mensagem, para o balao poder se destacar; e na
 * conversa, para a lista saber sem precisar abrir cada uma. Sem a segunda, o aviso so existiria
 * depois de a pessoa ja ter entrado — que e tarde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('mencao')->default(false);
        });

        Schema::table('conversations', function (Blueprint $table) {
            // Guarda QUANDO, e nao um sim/nao: assim a lista pode ordenar e o aviso some
            // quando alguem abre, sem precisar varrer as mensagens de novo.
            $table->timestamp('mencao_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('mencao_em'));
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('mencao'));
    }
};
