<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ficha de pessoa fica completa: pessoa fisica ou juridica, documento e papeis.
 *
 * NATUREZA E COLUNA NOVA, e nao reuso do 'tipo' que ja existe: 'tipo' ali diz se o contato e uma
 * PESSOA ou um GRUPO do WhatsApp — coisa do canal, nao do cadastro. Empilhar dois significados
 * na mesma coluna e o tipo de economia que se paga com juros no primeiro relatorio.
 *
 * PAPEIS E LISTA, e nao um campo unico. A mesma empresa e cliente e fornecedora; o tecnico
 * tambem e colaborador. Com campo unico, ou se escolhe um e o cadastro mente, ou se cria a
 * mesma pessoa duas vezes — e ai o historico de conversa fica partido entre as duas fichas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->string('natureza', 10)->nullable()->after('tipo');
            $t->string('documento', 20)->nullable()->after('natureza');
            $t->string('razao_social', 160)->nullable()->after('documento');
            $t->string('nome_fantasia', 160)->nullable()->after('razao_social');
            $t->json('papeis')->nullable()->after('nome_fantasia');
            $t->date('nascimento')->nullable()->after('papeis');

            // Buscar pelo CNPJ e o caminho normal de "essa empresa ja esta cadastrada?".
            $t->index(['tenant_id', 'documento']);
        });

        DB::statement(
            "ALTER TABLE contacts ADD CONSTRAINT contacts_natureza_check
             CHECK (natureza IS NULL OR natureza IN ('fisica','juridica'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_natureza_check');

        Schema::table('contacts', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'documento']);
            $t->dropColumn(['natureza', 'documento', 'razao_social', 'nome_fantasia', 'papeis', 'nascimento']);
        });
    }
};
