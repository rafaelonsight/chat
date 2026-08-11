<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O catalogo do que ele vende: produtos e servicos.
 *
 * POR QUE EXISTE: a proposta hoje pede descricao e valor digitados a mao em cada linha. Duas
 * propostas do mesmo servico saem com textos diferentes e, mais cedo ou mais tarde, com precos
 * diferentes — e o cliente que recebeu a versao barata volta com ela na mao.
 *
 * O catalogo transforma isso: escolhe do que ja existe, e o preco vem de um lugar so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offerings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Codigo e opcional: quem nao usa nao e obrigado a inventar um.
            $t->string('codigo', 40)->nullable();
            $t->string('nome', 160);
            $t->text('descricao')->nullable();

            // produto ou servico. CHECK e nao enum, como no resto do projeto.
            $t->string('tipo', 12)->default('servico');

            $t->decimal('preco', 12, 2)->default(0);

            /*
             * RECORRENTE JA AQUI, e nao so na proposta.
             *
             * "Plataforma" e mensal por natureza; "implantacao" e uma vez por natureza. Deixar
             * essa decisao para o momento de montar a proposta e convidar o erro que mais dói:
             * cobrar mensalidade como parcela unica, ou o contrario.
             */
            $t->boolean('recorrente')->default(false);
            $t->string('periodicidade', 12)->nullable();

            // hora, mes, unidade — o que aparece ao lado do preco.
            $t->string('unidade', 20)->nullable();

            $t->boolean('ativo')->default(true);
            $t->timestamps();

            $t->index(['tenant_id', 'ativo']);
            // Dois itens com o mesmo codigo na mesma conta e erro de cadastro, nao escolha.
            $t->unique(['tenant_id', 'codigo']);
        });

        DB::statement(
            "ALTER TABLE offerings ADD CONSTRAINT offerings_tipo_check CHECK (tipo IN ('produto','servico'))"
        );

        // A linha da proposta lembra de onde veio, para relatorio por item vendido — e fica nula
        // quando a linha foi escrita a mao, que continua permitido.
        Schema::table('proposal_items', function (Blueprint $t) {
            $t->foreignId('offering_id')->nullable()->after('proposal_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('offering_id');
        });

        Schema::dropIfExists('offerings');
    }
};
