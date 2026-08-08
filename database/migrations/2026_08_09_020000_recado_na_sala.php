<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * O bate-papo de dentro da sala de video.
 *
 * GRAVADO, e nao so ao vivo. Num sistema de atendimento, o que se digita durante a chamada e
 * justamente o que nao pode sumir: o numero de serie que o cliente leu do aparelho, o endereco
 * que ele corrigiu, o link que o tecnico colou. Chat que evapora ao fechar a aba faz a pessoa
 * ter de pedir tudo de novo por WhatsApp depois — ou pior, nao pedir e errar a visita.
 *
 * E E O QUE DEIXA QUEM CHEGA ATRASADO SE SITUAR: entrou dez minutos depois e le o que ja foi
 * dito, em vez de perguntar "o que eu perdi?" e parar a reuniao.
 *
 * O convidado nao tem conta: fica o nome que ele digitou na entrada, do mesmo jeito que na
 * lista de participantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

            // Nulo e convidado de fora. Quem tem id e gente da equipe.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nome', 80);
            $table->text('corpo');

            $table->timestamps();

            $table->index(['meeting_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_messages');
    }
};
