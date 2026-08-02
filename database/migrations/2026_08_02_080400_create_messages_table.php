<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // denormalizado so para sustentar o unique com external_id, que e a
            // defesa contra duplicacao quando o gateway reentrega o webhook
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('direcao', 3);
            $table->string('tipo')->default('text');
            $table->text('corpo')->nullable();
            $table->string('external_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('erro')->nullable();
            $table->timestamp('enviada_em')->nullable();
            $table->timestamps();

            // no PostgreSQL varios NULL nao colidem: mensagens de saida ainda
            // sem external_id convivem sem problema
            $table->unique(['channel_id', 'external_id']);
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
