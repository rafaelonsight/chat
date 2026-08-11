<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recado direto de uma pessoa da equipe para outra.
 *
 * TABELA PROPRIA, E NAO UMA CONVERSA COM O CLIENTE. Sao coisas diferentes com o mesmo formato:
 * a conversa tem canal, fila, tempo de espera, janela de 24h, dono do atendimento e relatorio.
 * Nada disso faz sentido entre dois colegas, e enfiar recado interno na tabela de conversas
 * envenenaria todo numero do produto — tempo medio de resposta contando conversa de corredor.
 *
 * SEM ESCOPO DE ACESSO POR CANAL AQUI, de proposito: o acesso por canal e por time diz o que a
 * pessoa pode ver do CLIENTE. Recado entre colegas nao e sobre cliente nenhum — quem tem acesso
 * e quem esta na conversa, e so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('de_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('para_user_id')->constrained('users')->cascadeOnDelete();
            $t->text('corpo');
            $t->timestamp('lida_em')->nullable();
            $t->timestamps();

            // As duas perguntas que a tela faz: "quantos nao li?" e "o que eu e fulano
            // dissemos?". Uma por indice, para nenhuma das duas varrer a tabela.
            $t->index(['para_user_id', 'lida_em']);
            $t->index(['de_user_id', 'para_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_messages');
    }
};
