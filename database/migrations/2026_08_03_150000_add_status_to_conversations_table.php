<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('status')->default('nova')->after('contact_id');
            // Quem esta conduzindo. Hoje sempre humano; quando a IA entrar, e
            // este campo que distingue quem responde.
            $table->foreignId('atendente_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();

            // e a consulta que cada aba faz
            $table->index(['tenant_id', 'status', 'ultima_msg_em']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status', 'ultima_msg_em']);
            $table->dropConstrainedForeignId('atendente_id');
            $table->dropColumn('status');
        });
    }
};
