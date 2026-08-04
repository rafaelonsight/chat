<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Definicao e valor em tabelas separadas, nao um jsonb solto no contato. O TIPO
// precisa existir antes do valor: e ele que diz a tela o que desenhar, o que
// validar, e depois permite segmentar campanha por aquele campo. jsonb nao daria
// nenhuma das tres.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $t->string('nome');
            $t->string('tipo');

            // Só para lista e multiselecao.
            $t->jsonb('opcoes')->default('[]');

            $t->boolean('obrigatorio')->default(false);
            $t->unsignedInteger('ordem')->default(0);
            $t->string('ajuda')->nullable();

            $t->timestamps();
            $t->index(['tenant_id', 'ordem']);
        });

        // Dois campos com o mesmo nome sao indistinguiveis no formulario.
        DB::statement('create unique index contact_fields_nome_unico on contact_fields (tenant_id, lower(nome))');

        Schema::create('contact_field_values', function (Blueprint $t) {
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contact_field_id')->constrained()->cascadeOnDelete();

            // Texto para todos os tipos, convertido na leitura. Coluna por tipo
            // seria uma tabela cheia de nulos; multiselecao guarda JSON aqui.
            $t->text('valor')->nullable();

            $t->timestamps();
            $t->primary(['contact_id', 'contact_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_field_values');
        Schema::dropIfExists('contact_fields');
    }
};
