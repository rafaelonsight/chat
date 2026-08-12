<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O jid deixa de ser obrigatorio: existe gente cadastrada que nao fala pelo WhatsApp.
 *
 * POR QUE AGORA: a ficha de pessoa passou a valer para colaborador, tecnico, fornecedor e vendedor.
 * Quatro dos cinco papeis nao chegam por conversa — chegam por cadastro. Exigir telefone de
 * WhatsApp para cadastrar um fornecedor e exigir um dado que ninguem tem, e o resultado previsivel
 * e telefone inventado no cadastro.
 *
 * O UNIQUE (tenant_id, jid) CONTINUA VALENDO e nao atrapalha: no Postgres, nulo nao conflita com
 * nulo. Duas pessoas sem WhatsApp convivem; duas com o mesmo WhatsApp continuam impedidas, que e
 * exatamente o que o indice existe para garantir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contacts ALTER COLUMN jid DROP NOT NULL');
    }

    /**
     * Voltar so e possivel enquanto ninguem usou o que foi liberado.
     *
     * Inventar um jid para quem nao tem seria pior que falhar: o dado falso sobreviveria a
     * migracao e ninguem saberia de onde veio.
     */
    public function down(): void
    {
        if (DB::table('contacts')->whereNull('jid')->exists()) {
            throw new RuntimeException(
                'Existe pessoa cadastrada sem WhatsApp. Voltar esta migracao exigiria inventar '
                .'um jid para ela: resolva a mao antes.'
            );
        }

        DB::statement('ALTER TABLE contacts ALTER COLUMN jid SET NOT NULL');
    }
};
