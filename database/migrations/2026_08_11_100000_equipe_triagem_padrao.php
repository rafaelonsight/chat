<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Triagem nas contas que ja existiam, e a fila que ficou sem time.
 *
 * O MODELO ja cria a Triagem em toda conta nova. Esta migracao e para as antigas — e para as
 * conversas que chegaram antes dela existir.
 *
 * POR QUE A FILA E MOVIDA: com o acesso por time, conversa sem time e conversa que nenhum
 * atendente restrito ve. Deixar as antigas sem time seria deixar um pedaco do historico
 * invisivel exatamente para quem trabalha nele. "Triagem e a equipe padrao" quer dizer isso: o
 * lugar de quem ainda nao foi direcionado.
 *
 * DA PARA VOLTAR: o down() devolve para sem time o que esta na Triagem. Perde-se a distincao
 * entre "estava sem time antes" e "foi movido agora", e por isso o down existe mas nao promete
 * ser exato — o que importa e nao ficar preso.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $triagem = Team::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'nome' => Team::TRIAGEM],
                ['descricao' => 'Fila de entrada: conversa nova cai aqui até ser direcionada.', 'ativa' => true],
            );

            DB::table('conversations')
                ->where('tenant_id', $tenantId)
                ->whereNull('team_id')
                ->update(['team_id' => $triagem->id]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $triagem = DB::table('teams')
                ->where('tenant_id', $tenantId)
                ->where('nome', Team::TRIAGEM)
                ->first();

            if (! $triagem) {
                continue;
            }

            DB::table('conversations')
                ->where('tenant_id', $tenantId)
                ->where('team_id', $triagem->id)
                ->update(['team_id' => null]);
        }
    }
};
