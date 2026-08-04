<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Campos que a consulta de CNPJ na Receita preenche. Ficam nullable porque o
// cadastro pode ser CPF, e porque MEI e empresa nova as vezes vem sem parte
// disso. Guardar em coluna, e nao num json, porque endereco de emissor de nota
// vai ser lido por CEP, cidade e UF quando entrar NFCom.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('nome_fantasia')->nullable()->after('razao_social');

            $table->string('cep', 8)->nullable()->after('telefone');
            $table->string('logradouro')->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('logradouro');
            $table->string('complemento')->nullable()->after('numero');
            $table->string('bairro')->nullable()->after('complemento');
            $table->string('cidade')->nullable()->after('bairro');
            $table->string('uf', 2)->nullable()->after('cidade');

            $table->string('natureza_juridica')->nullable()->after('uf');
            $table->string('cnae_principal')->nullable()->after('natureza_juridica');
            $table->string('situacao_cadastral', 40)->nullable()->after('cnae_principal');
            $table->string('porte', 40)->nullable()->after('situacao_cadastral');
            $table->date('data_abertura')->nullable()->after('porte');

            // Quando a Receita foi consultada. Sem isso nao se sabe se o dado na
            // tela e de hoje ou de dois anos atras.
            $table->timestamp('cnpj_consultado_em')->nullable()->after('data_abertura');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'nome_fantasia', 'cep', 'logradouro', 'numero', 'complemento',
                'bairro', 'cidade', 'uf', 'natureza_juridica', 'cnae_principal',
                'situacao_cadastral', 'porte', 'data_abertura', 'cnpj_consultado_em',
            ]);
        });
    }
};
