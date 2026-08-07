<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Marca de que os dados deste contato foram removidos a pedido dele.
 *
 * Existe para a tela poder dizer a verdade: sem a marca, um contato anonimizado seria
 * indistinguivel de um contato que nunca teve nome — e alguem tentaria "consertar" o cadastro
 * digitando o nome de novo, desfazendo um pedido legal sem saber.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('anonimizado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('anonimizado_em');
        });
    }
};
