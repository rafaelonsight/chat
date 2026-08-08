<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sala de espera: quem chega de fora bate na porta, e alguem da equipe abre.
 *
 * POR QUE ELA PRECISA EXISTIR. O link e a credencial, e link de reuniao circula em grupo de
 * WhatsApp: basta um encaminhamento para alguem que nao foi convidado entrar sem que ninguem
 * perceba. Com a espera, entrar deixa de ser silencioso — tem sempre uma pessoa decidindo.
 *
 * LIGADA POR PADRAO, e essa e a escolha conservadora de proposito: quem esquece de ligar uma
 * protecao descobre o problema pelo estrago, e quem acha a espera chata desliga no primeiro
 * uso e nunca mais pensa nisso.
 *
 * QUEM E DA CONTA NAO ESPERA. O atendente que abriu a sala nao vai pedir licenca para entrar
 * nela — a espera existe contra quem esta fora, nao contra a equipe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('sala_de_espera')->default(true)->after('max_participantes');
        });

        Schema::create('meeting_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

            $table->string('nome', 80);

            /*
             * aguardando | aceito | recusado
             *
             * O pedido nao e apagado quando decidido: a tela de quem esta esperando precisa
             * saber a diferenca entre "ainda nao responderam" e "recusaram". Apagar deixaria
             * as duas iguais, e a pessoa ficaria olhando para uma tela de espera que nunca
             * mais vai mudar.
             */
            $table->string('status', 20)->default('aguardando');

            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();

            $table->timestamps();

            $table->index(['meeting_id', 'status']);
        });

        DB::statement("alter table meeting_requests add constraint meeting_requests_status_check
                       check (status in ('aguardando','aceito','recusado'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_requests');

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('sala_de_espera');
        });
    }
};
