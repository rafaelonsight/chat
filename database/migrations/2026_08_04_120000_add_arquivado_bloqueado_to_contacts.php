<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            // Instante, nao booleano: saber QUANDO foi arquivado ou bloqueado e a
            // primeira pergunta quando alguem reclama de nao estar sendo atendido.
            $t->timestamp('arquivado_em')->nullable()->after('uf');
            $t->timestamp('bloqueado_em')->nullable()->after('arquivado_em');
            $t->string('bloqueio_motivo')->nullable()->after('bloqueado_em');

            $t->index(['tenant_id', 'bloqueado_em']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'bloqueado_em']);
            $t->dropColumn(['arquivado_em', 'bloqueado_em', 'bloqueio_motivo']);
        });
    }
};
