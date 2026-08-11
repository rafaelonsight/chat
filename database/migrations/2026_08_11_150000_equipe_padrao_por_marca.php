<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A equipe padrao passa a ser reconhecida por MARCA, e nao pelo nome.
 *
 * O DEFEITO QUE ISTO CONSERTA: eu achava a equipe de entrada procurando por nome ('Triagem').
 * Bastava alguem renomear ela para "Recepcao" e a fila de entrada quebrava EM SILENCIO — a
 * conversa nova passaria a nascer sem equipe, e desde que equipe virou permissao, sem equipe
 * quer dizer invisivel para todo atendente. Nenhum erro apareceria; so parava de funcionar.
 *
 * Com a marca, o Rafael pode chamar essa equipe do que quiser.
 *
 * E ela e o que sustenta o pedido dele: "a equipe Triagem e padrao e nao pode excluir". Regra
 * que depende de alguem lembrar nao e regra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $t) {
            $t->boolean('padrao')->default(false);
        });

        // Cada conta tem UMA padrao: a que hoje se chama Triagem. Se por acaso houver duas com
        // o mesmo nome (nao ha, o indice unico impede), a mais antiga ganha.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $id = DB::table('teams')
                ->where('tenant_id', $tenantId)
                ->where('nome', 'Triagem')
                ->orderBy('id')
                ->value('id');

            if ($id) {
                DB::table('teams')->where('id', $id)->update(['padrao' => true]);
            }
        }

        /*
         * UMA PADRAO POR CONTA, garantido pelo banco.
         *
         * Indice unico parcial: vale so onde padrao e verdadeiro. Sem ele, duas padroes na
         * mesma conta virariam uma escolha silenciosa de "qual delas" em cada consulta.
         */
        DB::statement(
            'CREATE UNIQUE INDEX teams_uma_padrao_por_conta ON teams (tenant_id) WHERE padrao'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS teams_uma_padrao_por_conta');

        Schema::table('teams', function (Blueprint $t) {
            $t->dropColumn('padrao');
        });
    }
};
