<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pesquisa de satisfacao.
 *
 * A nota fica na CONVERSA e nao no contato: um cliente pode ser bem atendido hoje e mal
 * atendido no mes que vem, e uma nota por pessoa apagaria a diferenca — que e justamente o que
 * o dono precisa enxergar.
 *
 * pesquisa_enviada_em serve para dois propositos: saber que a pergunta saiu, e ter uma janela
 * de tempo. Um "5" que chega tres dias depois nao e resposta da pesquisa; e outra coisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('pesquisa_ativa')->default(false);
            $table->text('pesquisa_texto')->nullable();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('pesquisa_enviada_em')->nullable();
            $table->unsignedSmallInteger('satisfacao')->nullable();
            $table->timestamp('satisfacao_em')->nullable();
        });

        // CHECK e nao enum, como no resto do banco. Nota fora de 1..5 nao e nota errada: e
        // sinal de que alguem escreveu no lugar errado, e melhor o banco recusar do que o
        // relatorio tirar media de um 47.
        DB::statement('alter table conversations add constraint conversations_satisfacao_check
                       check (satisfacao is null or (satisfacao >= 1 and satisfacao <= 5))');
    }

    public function down(): void
    {
        DB::statement('alter table conversations drop constraint if exists conversations_satisfacao_check');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['pesquisa_enviada_em', 'satisfacao', 'satisfacao_em']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['pesquisa_ativa', 'pesquisa_texto']);
        });
    }
};
