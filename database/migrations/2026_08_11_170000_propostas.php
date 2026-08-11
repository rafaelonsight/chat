<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propostas comerciais.
 *
 * A PROPOSTA E UMA PAGINA COM LINK, e nao um PDF anexado. Essa decisao esta no formato dos
 * dados: existe TOKEN (o endereco publico), existe registro de VISUALIZACAO (quem abriu, quando,
 * quantas vezes) e existe registro de ACEITE (nome, data, IP). Nada disso caberia num arquivo
 * enviado por anexo — e e justamente isso que muda taxa de fechamento: saber que o cliente abriu
 * tres vezes e parou no preco diz a hora de ligar.
 *
 * O PDF continua existindo, gerado pela impressao da propria pagina. Por isso nao ha biblioteca
 * de PDF aqui: uma a menos para manter, e o papel sai identico ao que o cliente viu na tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * O numero que o cliente le: PROP-2026-014.
             *
             * Sequencial POR CONTA, e nao o id da tabela: id global entrega quantas propostas o
             * sistema inteiro ja fez, e a primeira proposta de um cliente novo sairia como
             * "PROP-2026-3184" — que conta uma historia que nao e dele.
             */
            $t->string('numero', 24);

            $t->string('titulo', 160);

            // O contato, quando ja existe no CRM. Nulo porque proposta e frequentemente a
            // PRIMEIRA coisa que se manda para alguem, antes de haver conversa.
            $t->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            // E o nome de quem recebe, sempre preenchido: e o que vai na capa. Vem do contato
            // quando ha um, digitado quando nao ha.
            $t->string('cliente_nome', 160);
            $t->string('cliente_email', 160)->nullable();

            /*
             * A conversa do negocio, para o funil andar sozinho no aceite. Nula porque a
             * proposta pode nascer antes de existir conversa — e nesse caso o funil simplesmente
             * nao se move, em vez de a proposta falhar.
             */
            $t->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            // O CHECK do status vem depois do create, no ALTER TABLE la embaixo: e a convencao
            // do projeto (CHECK em vez de enum, porque alterar enum no Postgres e cirurgia).
            $t->string('status', 16)->default('rascunho');

            $t->date('validade')->nullable();

            /*
             * DOIS TOTAIS, e nao um.
             *
             * Ele vende implantacao (uma vez) e mensalidade (recorrente) na MESMA proposta.
             * Somar as duas coisas num numero so daria um total que nao existe na vida real —
             * "R$ 12.000" quando o certo e "R$ 12.000 + R$ 890/mes".
             */
            $t->decimal('total_unico', 12, 2)->default(0);
            $t->decimal('total_recorrente', 12, 2)->default(0);
            $t->decimal('desconto', 12, 2)->default(0);

            // Os blocos de conteudo (capa, escopo, prazo, termos) em ordem. JSON porque bloco
            // nao tem vida propria: nasce, morre e e editado junto da proposta.
            $t->json('blocos')->nullable();

            // O endereco publico. Aleatorio e nao sequencial: numero de proposta em URL deixa
            // qualquer um trocar o 14 por 13 e ler a proposta do concorrente.
            $t->string('token', 64)->unique();

            $t->timestamp('enviada_em')->nullable();
            $t->timestamp('vista_em')->nullable();      // a PRIMEIRA vez, para ordenar a lista
            $t->timestamp('aceita_em')->nullable();
            $t->string('aceita_por', 160)->nullable();
            $t->string('aceita_ip', 45)->nullable();
            $t->string('aceita_agente', 255)->nullable();
            $t->timestamp('recusada_em')->nullable();
            $t->text('recusa_motivo')->nullable();

            $t->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            // Duas propostas com o mesmo numero na mesma conta e erro de numeracao, nao escolha.
            $t->unique(['tenant_id', 'numero']);
            $t->index(['tenant_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE proposals ADD CONSTRAINT proposals_status_check
             CHECK (status IN ('rascunho','enviada','vista','aceita','recusada'))"
        );

        Schema::create('proposal_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $t->string('descricao', 255);
            $t->decimal('quantidade', 10, 2)->default(1);
            $t->decimal('valor_unitario', 12, 2)->default(0);

            // Mensalidade ou uma vez so: e o que separa os dois totais.
            $t->boolean('recorrente')->default(false);
            $t->string('periodicidade', 12)->nullable(); // mensal | anual
            $t->unsignedInteger('ordem')->default(0);
            $t->timestamps();

            $t->index(['proposal_id', 'ordem']);
        });

        /*
         * Cada abertura, e nao so a ultima.
         *
         * "Abriu 4 vezes" e informacao de venda; "abriu" e so um sim. E o tempo entre a primeira
         * e a ultima abertura diz se a proposta esta circulando na empresa do cliente.
         */
        Schema::create('proposal_views', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $t->timestamp('vista_em');
            $t->string('ip', 45)->nullable();
            $t->string('agente', 255)->nullable();
            $t->timestamps();

            $t->index(['proposal_id', 'vista_em']);
        });

        /*
         * O ponto de partida por linha de produto: chat, sistema, consultoria.
         *
         * Sem modelo, cada proposta comeca de uma pagina em branco — e proposta escrita as
         * pressas sai pior que a anterior, nao melhor.
         */
        Schema::create('proposal_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('nome', 120);
            $t->string('titulo_padrao', 160)->nullable();
            $t->json('blocos')->nullable();
            $t->json('itens')->nullable();
            $t->unsignedSmallInteger('validade_dias')->default(15);
            $t->boolean('ativo')->default(true);
            $t->timestamps();

            $t->unique(['tenant_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_templates');
        Schema::dropIfExists('proposal_views');
        Schema::dropIfExists('proposal_items');
        Schema::dropIfExists('proposals');
    }
};
