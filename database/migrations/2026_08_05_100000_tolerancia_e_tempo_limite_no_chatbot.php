<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duas correcoes de comportamento do bot, no mesmo lugar do banco.
 *
 * TOLERANCIA: quanto esperar para juntar mensagens seguidas do cliente. Sem isso o
 * motor roda uma vez por mensagem, e quem escreve "oi", "bom dia", "minha internet
 * caiu" queima as tentativas e e jogado para um humano sem ter escolhido nada.
 *
 * TEMPO LIMITE: quanto esperar por uma resposta que nao vem. Sem isso a conversa fica
 * em "aguardando" para sempre e ninguem sabe se o bot ainda esta trabalhando nela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $t) {
            // 8 segundos: sobra para uma frase quebrada em duas ou tres mensagens e
            // nao chega ao ponto de o cliente achar que o robo morreu. 0 desliga.
            $t->smallInteger('tolerancia_segundos')->default(8);

            // 0 desliga, e e o padrao de proposito: passar a encerrar conversa por
            // inatividade e mudanca de politica de atendimento, nao detalhe tecnico —
            // quem ligar tem de escolher ligar.
            $t->integer('espera_segundos')->default(0);

            $t->string('espera_acao', 20)->default('atendente');
            $t->text('mensagem_sem_resposta')->nullable();
        });

        Schema::table('conversations', function (Blueprint $t) {
            // Ate onde o bot ja LEU. O agrupamento pega o que veio depois disto, e
            // fica auditavel saber o que ele leu junto.
            $t->unsignedBigInteger('chatbot_visto_msg_id')->nullable();

            // Contador que invalida temporizador velho. Toda mudanca de estado o
            // incrementa; job atrasado que chega com marca antiga sai calado. E mais
            // confiavel que tentar remover job da fila.
            $t->integer('chatbot_marca')->default(0);
        });

        // CHECK em vez de enum: enum no Postgres exige ALTER TYPE para crescer.
        \Illuminate\Support\Facades\DB::statement(
            "alter table chatbots add constraint chatbots_espera_acao_valida
             check (espera_acao in ('atendente', 'concluir'))"
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('alter table chatbots drop constraint if exists chatbots_espera_acao_valida');

        Schema::table('chatbots', function (Blueprint $t) {
            $t->dropColumn(['tolerancia_segundos', 'espera_segundos', 'espera_acao', 'mensagem_sem_resposta']);
        });

        Schema::table('conversations', function (Blueprint $t) {
            $t->dropColumn(['chatbot_visto_msg_id', 'chatbot_marca']);
        });
    }
};
