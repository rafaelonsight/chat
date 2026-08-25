<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
            $table->string('nome', 60);
            $table->string('tipo', 20)->default('texto');
            $table->jsonb('opcoes')->nullable();
            $table->boolean('obrigatorio')->default(false);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'funnel_id', 'ordem']);
        });

        Schema::create('board_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
            $table->foreignId('funnel_stage_id')->constrained('funnel_stages')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('titulo', 160);
            $table->text('descricao')->nullable();
            $table->string('prioridade', 20)->default('normal');
            $table->timestamp('prazo_em')->nullable();
            $table->timestamp('etapa_em')->nullable();
            $table->jsonb('dados')->default('{}');
            $table->timestamp('arquivado_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'funnel_id', 'funnel_stage_id']);
            $table->index(['tenant_id', 'responsavel_id', 'prazo_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_items');
        Schema::dropIfExists('board_fields');
    }
};
