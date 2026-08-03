<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('transcricao')->nullable()->after('legenda');
            // pendente | pronta | falhou | ignorada
            $table->string('transcricao_status')->nullable()->after('transcricao');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['transcricao', 'transcricao_status']);
        });
    }
};
