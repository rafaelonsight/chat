<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 'operador' e quem opera o PRODUTO; 'admin' e quem manda na CONTA de um cliente.
 *
 * Sao coisas diferentes e ate hoje so existia a segunda. Sem essa separacao, dar a alguem a
 * tela de cadastrar clientes significaria dar a todo administrador de todo cliente — e cada
 * um deles veria a lista inteira de clientes do Rafael, com nome e CNPJ dos concorrentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('operador')->default(false)->after('admin');
        });

        // Quem ja opera o produto hoje: os administradores do PRIMEIRO tenant, que e a conta
        // da casa. Numa instalacao nova nao existe tenant nenhum e nada e marcado — ninguem
        // nasce dono por acidente.
        $casa = DB::table('tenants')->orderBy('id')->value('id');

        if ($casa) {
            DB::table('users')->where('tenant_id', $casa)->where('admin', true)
                ->update(['operador' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('operador');
        });
    }
};
