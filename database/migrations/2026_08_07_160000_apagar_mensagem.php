<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Mensagem apagada continua existindo como LINHA, e perde o conteudo na tela.
 *
 * Apagar a linha de verdade abriria buraco no historico: a conversa passaria de "bom dia" para
 * "combinado entao" sem nada no meio, e ninguem entenderia a propria conversa seis meses
 * depois. Guardar a marca e o que o WhatsApp tambem faz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('apagada_em')->nullable()->after('reacao_nossa');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('apagada_em');
        });
    }
};
