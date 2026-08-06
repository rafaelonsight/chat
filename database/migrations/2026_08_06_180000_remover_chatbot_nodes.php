<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove a arvore antiga do chatbot.
 *
 * Nao era codigo morto — era pior: um SEGUNDO editor ainda ligado na tela, com um campo
 * chamado "Exatamente o que o cliente recebe" que lia essa arvore. Com a arvore vazia e o
 * motor rodando pelos passos novos, o campo mostrava "Olá!" enquanto o cliente recebia a
 * saudacao com o menu inteiro. Tela que mente sobre o que o cliente recebe e pior que tela
 * que nao mostra nada.
 *
 * O motor de verdade e ChatbotMotor sobre chatbot_steps / chatbot_actions / chatbot_edges.
 *
 * SEM down() que recria: a tabela vai vazia (zero linhas em producao) e o codigo que a
 * entendia foi apagado no mesmo commit. Recriar a estrutura sem o codigo devolveria um
 * esqueleto que ninguem sabe preencher — e voltar atras de verdade e reverter o commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('conversations', 'chatbot_node_id')) {
            Schema::table('conversations', function (Blueprint $tabela) {
                $tabela->dropConstrainedForeignId('chatbot_node_id');
            });
        }

        Schema::dropIfExists('chatbot_nodes');
    }

    public function down(): void
    {
        // Irreversivel de proposito: ver o comentario acima.
    }
};
