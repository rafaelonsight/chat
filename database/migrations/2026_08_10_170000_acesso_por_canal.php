<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quais canais cada pessoa pode ver.
 *
 * TABELA DE LIGACAO, e nao uma coluna no usuario: a mesma pessoa atende dois numeros e um
 * numero e atendido por varias pessoas. Coluna unica obrigaria a escolher qual das duas
 * verdades cabe, e a outra viraria remendo.
 *
 * SEM LINHA NENHUMA = VE TODOS OS CANAIS. E o unico padrao que nao tranca ninguem para fora no
 * dia em que isto sobe: hoje nenhum usuario tem canal vinculado, e "vazio = nada" apagaria a
 * tela de todos os atendentes de uma vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $t->timestamps();

            // O mesmo par duas vezes nao significa "dobro de acesso": significa erro de tela.
            $t->unique(['user_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_user');
    }
};
