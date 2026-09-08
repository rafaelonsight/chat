<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('palavra_escape')->nullable()->default('atendente')->change();
        });
    }

    public function down(): void
    {
        // Nao reativar escapes desabilitados silenciosamente durante um rollback.
        if (\Illuminate\Support\Facades\DB::table('chatbots')->whereNull('palavra_escape')->exists()) {
            throw new RuntimeException('Existem fluxos sem escape. Defina suas palavras antes de reverter.');
        }

        Schema::table('chatbots', function (Blueprint $table) {
            $table->string('palavra_escape')->nullable(false)->default('atendente')->change();
        });
    }
};
