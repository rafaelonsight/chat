<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De onde veio a conversa.
 *
 * Quando o cliente chega por um anuncio Click-to-WhatsApp, a Meta manda um bloco "referral"
 * junto da PRIMEIRA mensagem — e somente junto dela. Nao existe consulta depois que devolva
 * isso: se nao guardarmos no momento em que chega, a informacao se perde para sempre.
 *
 * DUAS COLUNAS ESTRUTURADAS E UM JSON, de proposito. tipo e id sao o que relatorio agrupa e
 * filtra, entao viram coluna com indice. O resto — titulo, texto, imagem, url — e para
 * MOSTRAR, nunca para agrupar, e vira json: cada campo desses virar coluna seria uma
 * migracao por campo que a Meta inventar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $tabela) {
            $tabela->string('origem_tipo')->nullable()->after('nao_lidas');
            $tabela->string('origem_id')->nullable()->after('origem_tipo');
            $tabela->jsonb('origem')->nullable()->after('origem_id');

            // O relatorio pergunta "quantas conversas este anuncio trouxe", e essa pergunta
            // e por tenant: sem o tenant no indice, o banco varreria conversa de todo mundo.
            $tabela->index(['tenant_id', 'origem_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $tabela) {
            $tabela->dropIndex(['tenant_id', 'origem_id']);
            $tabela->dropColumn(['origem_tipo', 'origem_id', 'origem']);
        });
    }
};
