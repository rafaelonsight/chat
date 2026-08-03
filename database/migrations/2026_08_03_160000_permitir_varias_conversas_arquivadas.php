<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O unico antigo prendia o modelo a uma conversa por contato e canal
        // para sempre — era ele que obrigava a reabrir a arquivada.
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['channel_id', 'contact_id']);
        });

        // Quantas arquivadas o historico quiser, mas no maximo UMA aberta por
        // contato e canal. Garantido pelo banco: duas mensagens chegando ao
        // mesmo tempo nao conseguem criar conversa duplicada.
        DB::statement("
            create unique index conversations_abertas_unicas
                on conversations (channel_id, contact_id)
                where status <> 'arquivada'
        ");
    }

    public function down(): void
    {
        DB::statement('drop index if exists conversations_abertas_unicas');

        Schema::table('conversations', function (Blueprint $table) {
            $table->unique(['channel_id', 'contact_id']);
        });
    }
};
