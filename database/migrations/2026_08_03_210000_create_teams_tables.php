<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('nome');
            $table->string('descricao')->nullable();
            $table->string('cor')->nullable();
            $table->boolean('ativa')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'nome']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // atendente | supervisor. A coluna existe desde agora para o conceito
            // nao exigir migration dolorosa quando o supervisor ganhar poderes.
            $table->string('papel')->default('atendente');
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            // Equipe e FILA, nao etiqueta de pessoa: e para ca que o chatbot vai
            // entregar a conversa depois do "1 Financeiro, 2 Suporte".
            $table->foreignId('team_id')->nullable()->after('atendente_id')
                ->constrained()->nullOnDelete();

            $table->index(['tenant_id', 'team_id', 'status']);
        });

        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tipo');
            $table->string('descricao');
            $table->jsonb('dados')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_events');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'team_id', 'status']);
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
