<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fixar conversa no topo.
 *
 * Guarda QUEM fixou, e nao so que esta fixada. Uma conversa presa no topo e escolha de uma
 * pessoa sobre o proprio dia — se fosse da conta, o atendente que fixasse o caso dele
 * empurraria a lista de todo mundo, e o recurso seria desligado na primeira semana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('fixada_em')->nullable();
            $table->foreignId('fixada_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixada_por');
            $table->dropColumn('fixada_em');
        });
    }
};
