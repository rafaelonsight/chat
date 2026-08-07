<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Assinar as mensagens com o primeiro nome de quem respondeu.
 *
 * Nasce DESLIGADA. Ligar por padrao mudaria, na primeira atualizacao, o texto que todo cliente
 * de todo mundo recebe — sem ninguem ter pedido e sem ninguem entender de onde veio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('assinatura_ativa')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('assinatura_ativa');
        });
    }
};
