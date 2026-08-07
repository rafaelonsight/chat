<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Funil: colunas que a empresa define, e a CONVERSA como cartao.
 *
 * O CARTAO E A CONVERSA, e nao o contato. Duas razoes:
 *
 * 1. a mesma pessoa pode ter dois assuntos no mesmo mes — um orcamento fechado em julho e
 *    outro em negociacao em agosto. Com o contato como cartao, o segundo apagaria o primeiro;
 * 2. o historico do funil passa a casar com o historico do atendimento, que e onde a conversa
 *    de verdade aconteceu.
 *
 * A etapa mora numa coluna da conversa, e nao numa tabela de ligacao: um cartao esta em UMA
 * coluna por vez, e ligacao muitos-para-muitos permitiria o impossivel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnel_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('nome', 40);
            $table->string('cor', 20)->default('cinza');
            $table->unsignedSmallInteger('ordem')->default(0);
            // Etapa de saida: cartao que chega aqui saiu do funil, ganhou ou perdeu. Serve
            // para a contagem de "em andamento" nao incluir quem ja acabou.
            $table->boolean('encerra')->default(false);
            $table->timestamps();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('funnel_stage_id')->nullable()->constrained('funnel_stages')->nullOnDelete();
            $table->timestamp('etapa_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funnel_stage_id');
            $table->dropColumn('etapa_em');
        });

        Schema::dropIfExists('funnel_stages');
    }
};
