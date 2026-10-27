<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $t) {
            // Excecoes ao plano: ["agenda"] soma modulos; {"ia": false} desliga um a um.
            $t->jsonb('modulos')->nullable()->after('plano');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', fn (Blueprint $t) => $t->dropColumn('modulos'));
    }
};
