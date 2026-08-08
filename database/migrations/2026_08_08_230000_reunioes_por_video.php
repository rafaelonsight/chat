<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Reuniao por video.
 *
 * O CASO QUE MANDA E O ATENDENTE NO MEIO DA CONVERSA. Alguem descreve um problema por texto ha
 * quinze minutos; em trinta segundos de camera ele mostra o equipamento e acabou. Por isso a
 * reuniao nasce PRESA A CONVERSA: o link sai pelo WhatsApp por onde a pessoa ja estava
 * falando, e nao por e-mail que ela nao vai abrir.
 *
 * O TOKEN DO CONVIDADO E OPACO E UNICO NO BANCO INTEIRO, e nao derivavel do id nem do nome da
 * sala: link de reuniao circula em grupo de WhatsApp, e quem o tem entra sem login. Ele e a
 * credencial — entao precisa ser aleatorio, e precisa expirar.
 *
 * O NOME DA SALA TAMBEM E UNICO NO BANCO INTEIRO. Quem guarda a sala de verdade e o servidor
 * de midia, que nao sabe o que e tenant: duas contas com uma sala de mesmo nome cairiam uma
 * dentro da outra, e as duas veriam a reuniao da outra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('sala', 64)->unique();
            $table->string('token_convidado', 40)->unique();
            $table->string('titulo', 120)->nullable();

            $table->string('status', 20)->default('em_andamento');
            $table->unsignedSmallInteger('max_participantes')->default(8);

            $table->timestamp('comecou_em')->useCurrent();
            $table->timestamp('encerrada_em')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'comecou_em']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("alter table meetings add constraint meetings_status_check
                       check (status in ('em_andamento','encerrada'))");

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

            // Nulo e convidado de fora. Quem tem id e gente da equipe.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nome', 80);
            $table->timestamp('entrou_em')->useCurrent();

            $table->timestamps();

            $table->index(['meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
