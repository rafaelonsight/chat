<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A foto do consumo de cada mes.
 *
 * POR QUE FOTO E NAO CONSULTA. Este numero vai virar FATURA. Se ele fosse calculado toda vez,
 * mudaria depois de o mes fechar — apagar um canal leva as conversas dele por cascata, e o
 * julho que ja foi cobrado encolheria em setembro. Cobranca que muda depois de emitida e
 * discussao com o cliente, e ele tem razao.
 *
 * O mes corrente continua sendo calculado ao vivo: ele ainda esta acontecendo, e ninguem
 * faturou nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumo_mensal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Primeiro dia do mes. Data e nao texto: ordenar '2026-9' como texto poe setembro
            // depois de outubro.
            $table->date('mes');

            $table->unsignedInteger('conversas')->default(0);
            $table->unsignedInteger('mensagens_recebidas')->default(0);
            $table->unsignedInteger('mensagens_enviadas')->default(0);
            $table->unsignedInteger('contatos_alcancados')->default(0);

            $table->timestamp('fechado_em')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumo_mensal');
    }
};
