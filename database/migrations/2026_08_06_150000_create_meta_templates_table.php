<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates aprovados da Meta.
 *
 * Tabela PROPRIA, separada de message_templates, embora os dois se chamem "modelo" na
 * boca do atendente. Sao objetos diferentes com regras diferentes:
 *
 * - message_templates: texto nosso, com marcadores, que o atendente insere no campo. Sai
 *   como mensagem comum e SO funciona dentro da janela de 24h.
 * - meta_templates: registrado e aprovado pela Meta, com nome e idioma proprios. E a
 *   UNICA coisa que sai depois da janela fechar, e cada envio e cobrado.
 *
 * Juntar os dois faria a regra da janela mentir na tela — e essa e a regra que decide se o
 * cliente recebe ou nao a resposta.
 *
 * O template vive na WABA, nao no numero: dois numeros da mesma conta compartilham os
 * mesmos templates. Por isso a chave e meta_waba_id e nao channel_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_templates', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $tabela->string('meta_waba_id')->index();
            $tabela->string('meta_id')->nullable();
            $tabela->string('nome');
            $tabela->string('idioma');
            $tabela->string('categoria')->nullable();
            $tabela->string('status');

            $tabela->text('cabecalho')->nullable();
            $tabela->text('corpo');
            $tabela->text('rodape')->nullable();

            // O bruto inteiro fica guardado: quando algum componente novo aparecer, o
            // diagnostico e ler o que a Meta mandou, e nao pedir para o cliente reproduzir.
            $tabela->jsonb('componentes')->nullable();

            $tabela->unsignedSmallInteger('variaveis')->default(0);
            $tabela->boolean('suportado')->default(true);
            $tabela->string('motivo_nao_suportado')->nullable();
            $tabela->timestamp('sincronizado_em')->nullable();
            $tabela->timestamps();

            // A identidade de um template na Meta e nome + idioma dentro da conta. O mesmo
            // nome existe em varios idiomas e sao templates diferentes.
            $tabela->unique(['meta_waba_id', 'nome', 'idioma']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_templates');
    }
};
