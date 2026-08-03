<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('razao_social')->nullable()->after('nome');
            $table->string('documento')->nullable()->after('razao_social');
            $table->string('email')->nullable()->after('documento');
            $table->string('telefone')->nullable()->after('email');
            $table->string('fuso_horario')->default('America/Sao_Paulo')->after('telefone');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['razao_social', 'documento', 'email', 'telefone', 'fuso_horario']);
        });
    }
};
