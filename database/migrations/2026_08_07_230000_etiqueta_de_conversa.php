<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Etiqueta de CONVERSA, separada da etiqueta de CONTATO.
 *
 * O PROBLEMA QUE ISTO RESOLVE. Etiqueta de contato descreve a PESSOA e vale para sempre:
 * "Cliente VIP", "Inadimplente". Etiqueta de conversa descreve o que aconteceu NAQUELE
 * atendimento: "Orcamento enviado", "Reclamacao".
 *
 * Com uma coisa so, o relatorio historico mente. "Quantos orcamentos em julho?" era respondido
 * olhando quem tem a etiqueta HOJE — e se o cliente virou "Fechado" em agosto, julho encolhe
 * sozinho. Numero que muda depois de o mes fechar e numero em que ninguem confia.
 *
 * O CONTEXTO NASCE NA ETIQUETA, e nao no uso: assim "Cliente VIP" nem aparece na lista da
 * conversa, e o atendente nao tem como marcar no lugar errado. Etiqueta no lugar errado e
 * exatamente o que estraga o relatorio.
 *
 * As que ja existem viram 'contato', que e o que elas sempre foram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->string('contexto', 16)->default('contato');
        });

        DB::statement("alter table tags add constraint tags_contexto_check
                       check (contexto in ('contato','conversa'))");

        Schema::create('conversation_tag', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            // Mesmas colunas do contact_tag, e pela mesma razao: quando uma etiqueta aparecer
            // errada, alguem vai perguntar quem colocou.
            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origem', 20)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->primary(['conversation_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tag');

        DB::statement('alter table tags drop constraint if exists tags_contexto_check');

        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn('contexto');
        });
    }
};
