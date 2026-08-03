<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A arvore de menu (chatbot_nodes) so sabe fazer uma coisa por opcao. Um fluxo de
// verdade precisa de PASSOS com varias ACOES em ordem, e de ARESTAS ligando passos
// — inclusive com ramificacao. Sem isso nao existe "espere 5s", "guarde a
// resposta", "adicione etiqueta" nem condicional.
//
// Aditivo de proposito: as tabelas antigas continuam de pe e o motor atual segue
// funcionando enquanto o novo e construido. Expandir, migrar, sO DEPOIS contrair.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $t) {
            // Rascunho deixa mexer no fluxo sem afetar quem esta conversando agora.
            $t->string('status')->default('rascunho')->after('ativo');
            $t->unsignedInteger('versao')->default(1)->after('status');
            $t->timestamp('publicado_em')->nullable()->after('versao');
            $t->foreignId('team_id')->nullable()->after('channel_id')
                ->constrained()->nullOnDelete();
        });

        DB::statement("alter table chatbots add constraint chatbots_status_valido check (status in ('rascunho','publicado'))");

        // ------------------------------------------------------------- passos
        Schema::create('chatbot_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chatbot_id')->constrained()->cascadeOnDelete();

            $t->string('nome')->default('Novo grupo');

            // 'inicio' e o passo de entrada, um por fluxo. 'grupo' e o resto.
            $t->string('tipo')->default('grupo');

            // Posicao no canvas. Fica no banco porque o desenho que o usuario
            // arrumou e informacao dele, nao detalhe de renderizacao.
            $t->integer('x')->default(0);
            $t->integer('y')->default(0);

            $t->timestamps();
            $t->index(['chatbot_id', 'tipo']);
        });

        // Um unico passo de entrada por fluxo: dois inicios nao tem resposta para
        // "por onde comeca".
        DB::statement("create unique index chatbot_steps_um_inicio on chatbot_steps (chatbot_id) where tipo = 'inicio'");
        DB::statement("alter table chatbot_steps add constraint chatbot_steps_tipo_valido check (tipo in ('inicio','grupo'))");

        // -------------------------------------------------------------- acoes
        Schema::create('chatbot_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chatbot_id')->constrained()->cascadeOnDelete();
            $t->foreignId('step_id')->constrained('chatbot_steps')->cascadeOnDelete();

            $t->unsignedInteger('ordem')->default(0);
            $t->string('tipo');

            // Cada tipo tem seu proprio formato. jsonb porque a forma varia por
            // tipo e criar 12 colunas nulas seria pior.
            $t->jsonb('config')->default('{}');

            $t->timestamps();
            $t->index(['step_id', 'ordem']);
        });

        // ------------------------------------------------------------ arestas
        Schema::create('chatbot_edges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chatbot_id')->constrained()->cascadeOnDelete();

            $t->foreignId('from_step_id')->constrained('chatbot_steps')->cascadeOnDelete();

            // 'saida' e o caminho unico. Menu usa 'opcao:1', 'opcao:2'; condicional
            // usa 'sim' e 'nao'. E o que permite ramificar sem tabela extra.
            $t->string('from_handle')->default('saida');

            $t->foreignId('to_step_id')->constrained('chatbot_steps')->cascadeOnDelete();

            $t->timestamps();
        });

        // Uma saida so pode levar a um destino: duas arestas no mesmo handle nao
        // tem resposta para "para onde vai".
        DB::statement('create unique index chatbot_edges_saida_unica on chatbot_edges (from_step_id, from_handle)');

        // Aresta para o proprio passo seria laco infinito garantido.
        DB::statement('alter table chatbot_edges add constraint chatbot_edges_sem_autoligacao check (from_step_id <> to_step_id)');

        // ------------------------------------------------ estado da conversa
        Schema::table('conversations', function (Blueprint $t) {
            $t->foreignId('chatbot_step_id')->nullable()->after('chatbot_node_id')
                ->constrained('chatbot_steps')->nullOnDelete();

            // O que o bot esta esperando: 'menu', 'pergunta' ou nada.
            $t->string('chatbot_aguardando')->nullable()->after('chatbot_step_id');

            // De qual acao dentro do passo ele retoma quando a resposta chegar.
            $t->unsignedInteger('chatbot_acao_ordem')->default(0)->after('chatbot_aguardando');

            // Respostas capturadas por "Enviar pergunta", para usar em condicional
            // e em marcador de mensagem.
            $t->jsonb('chatbot_respostas')->default('{}')->after('chatbot_acao_ordem');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('chatbot_step_id');
            $t->dropColumn(['chatbot_aguardando', 'chatbot_acao_ordem', 'chatbot_respostas']);
        });

        Schema::dropIfExists('chatbot_edges');
        Schema::dropIfExists('chatbot_actions');
        Schema::dropIfExists('chatbot_steps');

        DB::statement('alter table chatbots drop constraint if exists chatbots_status_valido');

        Schema::table('chatbots', function (Blueprint $t) {
            $t->dropConstrainedForeignId('team_id');
            $t->dropColumn(['status', 'versao', 'publicado_em']);
        });
    }
};
