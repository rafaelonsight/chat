<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $tabela) {
            // Quando avisamos o WhatsApp que esta mensagem foi lida. Guardar o
            // instante (em vez de um booleano) evita remarcar a mesma mensagem a
            // cada abertura da conversa e serve de base para "tempo de primeira
            // leitura" no relatorio.
            $tabela->timestamp('lida_em')->nullable()->after('enviada_em');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $tabela) {
            $tabela->dropColumn('lida_em');
        });
    }
};
