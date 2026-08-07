<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Respondendo a esta mensagem".
 *
 * nullOnDelete e nao cascade: se a mensagem citada sumir um dia, quem respondeu continua
 * existindo — some apenas a citacao. Cascade apagaria a resposta junto, e resposta some
 * silenciosamente e conversa que deixa de fazer sentido para quem le o historico depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('responde_a_id')->nullable()->after('external_id')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responde_a_id');
        });
    }
};
