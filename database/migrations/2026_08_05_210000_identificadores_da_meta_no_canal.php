<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificadores da Meta ficam no CANAL, nao na configuracao global.
 *
 * Com Phone Number ID no .env, o segundo cliente nao caberia — e o OnChat existe para
 * atender vario. O token continua no .env por enquanto: um token por cliente so passa a
 * fazer sentido com o Cadastro Incorporado, que e quem emite um por WABA conectada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $t) {
            $t->string('meta_phone_number_id', 40)->nullable();
            $t->string('meta_waba_id', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $t) {
            $t->dropColumn(['meta_phone_number_id', 'meta_waba_id']);
        });
    }
};
