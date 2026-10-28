<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $t) {
            // Quando o administrador foi avisado da queda. Volta a nulo ao reconectar.
            $t->timestamp('queda_avisada_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', fn (Blueprint $t) => $t->dropColumn('queda_avisada_em'));
    }
};
