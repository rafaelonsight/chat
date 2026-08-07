<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Mais de um funil, cada um com as proprias etapas.
 *
 * Antes havia UM quadro por conta. Uma empresa que vende e tambem faz suporte precisa de dois
 * processos diferentes — "Orcamento, Negociacao, Fechado" nao descreve um chamado tecnico, e
 * forcar os dois no mesmo quadro faz a pessoa inventar etapas que nao servem para nenhum dos
 * dois.
 *
 * UMA CONVERSA FICA EM UM FUNIL DE CADA VEZ, e isso e decisao, nao limitacao de pressa: o
 * cartao e a conversa, e uma conversa esta num ponto de um processo. Se um dia o mesmo
 * atendimento precisar viver em dois quadros ao mesmo tempo, isso vira tabela de ligacao — e
 * ai o quadro deixa de responder "onde isto esta" para responder "em quantos lugares isto
 * esta", que e outra pergunta.
 *
 * A MIGRACAO NAO PERDE O QUE JA EXISTE: as etapas de cada conta viram um funil chamado
 * "Funil", e os cartoes continuam onde estavam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('nome', 60);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::table('funnel_stages', function (Blueprint $table) {
            $table->foreignId('funnel_id')->nullable()->after('tenant_id')
                ->constrained()->cascadeOnDelete();
        });

        // As etapas que ja existem ganham um funil, uma conta por vez.
        foreach (DB::table('funnel_stages')->select('tenant_id')->distinct()->pluck('tenant_id') as $conta) {
            $id = DB::table('funnels')->insertGetId([
                'tenant_id'  => $conta,
                'nome'       => 'Funil',
                'ordem'      => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('funnel_stages')->where('tenant_id', $conta)->update(['funnel_id' => $id]);
        }

        // So agora vira obrigatorio: etapa sem funil nao apareceria em quadro nenhum, e
        // sumiria da tela sem nada explicar.
        Schema::table('funnel_stages', function (Blueprint $table) {
            $table->foreignId('funnel_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('funnel_stages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funnel_id');
        });

        Schema::dropIfExists('funnels');
    }
};
