<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Campos fixos do contato. Endereco em coluna, e nao em campo personalizado,
// porque e endereco que a instalacao e a ordem de servico vao ler — e porque
// buscar contato por cidade num json seria consulta ruim desde o primeiro dia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email')->nullable()->after('nome');
            // Guardado sem @ e sem url: o que entra na tela pode vir dos tres
            // jeitos, e comparar depois exige uma forma so.
            $table->string('instagram')->nullable()->after('email');

            $table->string('cep', 8)->nullable()->after('instagram');
            $table->string('logradouro')->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('logradouro');
            $table->string('complemento')->nullable()->after('numero');
            $table->string('bairro')->nullable()->after('complemento');
            $table->string('cidade')->nullable()->after('bairro');
            $table->string('uf', 2)->nullable()->after('cidade');

            // Busca por cidade/UF e o filtro que a operacao pede primeiro.
            $table->index(['tenant_id', 'cidade']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'cidade']);

            $table->dropColumn([
                'email', 'instagram', 'cep', 'logradouro', 'numero',
                'complemento', 'bairro', 'cidade', 'uf',
            ]);
        });
    }
};
