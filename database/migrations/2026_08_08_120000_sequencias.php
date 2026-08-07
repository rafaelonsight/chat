<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sequencias: mensagens em cadencia, disparadas por um gatilho.
 *
 * A DIFERENCA PARA CAMPANHA, que precisa estar clara: campanha e UM disparo para MUITOS ao
 * mesmo tempo; sequencia e VARIAS mensagens para UMA pessoa ao longo do tempo. Campanha se
 * pensa em publico; sequencia se pensa em jornada.
 *
 * A REGRA QUE MANDA AQUI: para quando o cliente responde. Sem isso a sequencia vira
 * perseguicao — a pessoa responde, alguem atende, e a maquina continua mandando "notou que
 * voce nao respondeu?" no dia seguinte. E o jeito mais rapido de transformar automacao em
 * motivo de bloqueio.
 *
 * A INSCRICAO GUARDA O PROXIMO PASSO E A HORA DELE. Assim o tique so procura quem esta na
 * hora, em vez de recalcular a jornada de todo mundo a cada minuto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            $table->string('nome');
            $table->string('gatilho', 30);
            $table->boolean('ativa')->default(false);

            // Padrao LIGADO. Uma sequencia que continua depois da resposta e o caso em que ela
            // faz mal; se alguem quiser isso, que desligue de proposito.
            $table->boolean('parar_ao_responder')->default(true);

            // So para o gatilho "sem resposta".
            $table->unsignedSmallInteger('sem_resposta_horas')->default(24);

            $table->unsignedTinyInteger('hora_inicio')->default(9);
            $table->unsignedTinyInteger('hora_fim')->default(20);

            $table->timestamps();
        });

        DB::statement("alter table sequences add constraint sequences_gatilho_check
                       check (gatilho in ('primeira_conversa','atendimento_encerrado','sem_resposta'))");

        DB::statement('alter table sequences add constraint sequences_janela_check
                       check (hora_inicio < hora_fim)');

        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem');
            // Horas depois do passo anterior (ou do gatilho, no primeiro).
            $table->unsignedInteger('atraso_horas')->default(24);
            $table->text('corpo');
            $table->timestamps();

            $table->unique(['sequence_id', 'ordem']);
        });

        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('ativa');
            $table->unsignedSmallInteger('proximo_passo')->default(1);
            $table->timestamp('proximo_em')->nullable();
            $table->string('parada_motivo')->nullable();
            $table->timestamp('encerrada_em')->nullable();

            $table->timestamps();

            // O tique procura por aqui: quem esta ativa e ja passou da hora.
            $table->index(['status', 'proximo_em']);
        });

        DB::statement("alter table sequence_enrollments add constraint sequence_enrollments_status_check
                       check (status in ('ativa','concluida','parada'))");

        // Uma inscricao ATIVA por pessoa por sequencia. Sem isto, um cliente que abre tres
        // conversas em uma semana recebe a mesma jornada tres vezes em paralelo — e a culpa
        // parece do sistema, porque e.
        DB::statement('create unique index sequence_enrollments_ativa_unica
                       on sequence_enrollments (sequence_id, contact_id)
                       where status = \'ativa\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('sequence_steps');
        Schema::dropIfExists('sequences');
    }
};
