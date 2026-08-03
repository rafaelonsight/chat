<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Nulo = vale para todos os canais da conta.
            $t->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();

            $t->string('nome');
            $t->boolean('ativo')->default(false);

            $t->text('mensagem_boas_vindas');
            $t->text('mensagem_nao_entendi');

            // Vazio = o bot atende normalmente fora do horario. Um bot funciona 24h;
            // mas menu que encaminha para equipe que ninguem esta olhando e pior
            // que dizer "estamos fechados".
            $t->text('mensagem_fora_horario')->nullable();

            $t->text('mensagem_transferindo')->nullable();

            $t->unsignedSmallInteger('max_tentativas')->default(2);
            $t->string('palavra_escape')->default('atendente');

            $t->timestamps();
            $t->index(['tenant_id', 'ativo']);
        });

        // Dois bots ativos no mesmo canal seria ambiguidade: qual atende? O banco
        // impede. coalesce em indice de expressao resolve o NULL de channel_id,
        // que em unique comum nao colidiria com NULL.
        DB::statement('create unique index chatbots_um_ativo_por_canal on chatbots (tenant_id, coalesce(channel_id, 0)) where ativo');

        Schema::create('chatbot_nodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chatbot_id')->constrained()->cascadeOnDelete();

            // Nulo = opcao do menu raiz.
            $t->foreignId('parent_id')->nullable()->constrained('chatbot_nodes')->cascadeOnDelete();

            $t->unsignedInteger('ordem')->default(0);

            // O que o cliente digita para escolher: '1', '2'...
            $t->string('gatilho');
            $t->string('rotulo');

            // menu     = abre outro menu
            // mensagem = responde um texto e volta a mostrar o menu atual
            // equipe   = entrega para uma equipe (team_id nulo = qualquer atendente)
            $t->string('tipo')->default('mensagem');

            $t->text('mensagem')->nullable();
            $t->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            $t->timestamps();
            $t->index(['chatbot_id', 'parent_id', 'ordem']);
        });

        // Nao pode haver dois "1" no mesmo menu. Dois indices parciais porque em
        // unique comum o parent_id NULL (menu raiz) nao colidiria com NULL —
        // exatamente a armadilha que a tabela de horarios tinha.
        DB::statement('create unique index chatbot_nodes_raiz_unica on chatbot_nodes (chatbot_id, gatilho) where parent_id is null');
        DB::statement('create unique index chatbot_nodes_filha_unica on chatbot_nodes (chatbot_id, parent_id, gatilho) where parent_id is not null');

        // Coerencia do tipo: equipe sem entrega e mensagem sem texto nao fazem nada.
        DB::statement("alter table chatbot_nodes add constraint chatbot_nodes_tipo_coerente check (tipo in ('menu','mensagem','equipe'))");

        Schema::table('conversations', function (Blueprint $t) {
            $t->foreignId('chatbot_id')->nullable()->after('team_id')->constrained()->nullOnDelete();

            // Menu em que o cliente esta. Nulo com estado 'ativo' = menu raiz.
            $t->foreignId('chatbot_node_id')->nullable()->after('chatbot_id')
                ->constrained('chatbot_nodes')->nullOnDelete();

            $t->unsignedSmallInteger('chatbot_tentativas')->default(0)->after('chatbot_node_id');

            // null = nunca passou pelo bot | ativo | concluido | escapou
            $t->string('chatbot_estado')->nullable()->after('chatbot_tentativas');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('chatbot_node_id');
            $t->dropConstrainedForeignId('chatbot_id');
            $t->dropColumn(['chatbot_tentativas', 'chatbot_estado']);
        });

        Schema::dropIfExists('chatbot_nodes');
        Schema::dropIfExists('chatbots');
    }
};
