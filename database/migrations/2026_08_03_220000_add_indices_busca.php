<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A busca do inbox faz ilike '%termo%'. Sem trigrama isso e varredura completa:
// com 6 mensagens nao aparece, com 500 mil a tela morre. btree nao serve para
// curinga no inicio do padrao — por isso GIN com gin_trgm_ops.
return new class extends Migration
{
    private const INDICES = [
        'messages_corpo_trgm'         => ['messages', 'corpo'],
        'messages_legenda_trgm'       => ['messages', 'legenda'],
        'messages_transcricao_trgm'   => ['messages', 'transcricao'],
        'contacts_nome_trgm'          => ['contacts', 'nome'],
        'contacts_telefone_trgm'      => ['contacts', 'telefone_e164'],
    ];

    public function up(): void
    {
        // IF NOT EXISTS nao exige superusuario quando a extensao ja existe: e
        // no-op com aviso. Em instalacao nova, o operador precisa criar como
        // postgres antes de migrar.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::INDICES as $nome => [$tabela, $coluna]) {
            DB::statement("create index if not exists {$nome} on {$tabela} using gin ({$coluna} gin_trgm_ops)");
        }

        // conversations NAO ganha indice aqui: as migrations originais ja criaram
        // (tenant_id, status, ultima_msg_em), (tenant_id, ultima_msg_em) e
        // (tenant_id, team_id, status). Indice repetido custa escrita e disco sem
        // acelerar leitura nenhuma.
    }

    public function down(): void
    {
        foreach (array_keys(self::INDICES) as $nome) {
            DB::statement("drop index if exists {$nome}");
        }
    }
};
