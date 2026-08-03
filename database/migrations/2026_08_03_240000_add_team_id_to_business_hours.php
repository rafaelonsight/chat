<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_hours', function (Blueprint $tabela) {
            // O mesmo numero vai servir Suporte 24h e Financeiro comercial,
            // roteados pelo chatbot. O eixo do horario e a equipe, nao so o canal.
            $tabela->foreignId('team_id')->nullable()->after('channel_id')
                ->constrained()->cascadeOnDelete();
        });

        // A constraint antiga era unique (tenant_id, channel_id, dia_semana). No
        // Postgres NULL nao colide com NULL, entao a grade da CONTA (channel_id
        // nulo) nunca esteve protegida contra duplicata. Latente, nao ativa —
        // consertado aqui junto porque estou mexendo na tabela.
        DB::statement('alter table business_hours drop constraint if exists business_hours_tenant_id_channel_id_dia_semana_unique');

        DB::statement('create unique index business_hours_conta_unica on business_hours (tenant_id, dia_semana) where channel_id is null and team_id is null');
        DB::statement('create unique index business_hours_canal_unica on business_hours (tenant_id, channel_id, dia_semana) where channel_id is not null');
        DB::statement('create unique index business_hours_equipe_unica on business_hours (tenant_id, team_id, dia_semana) where team_id is not null');

        // Uma linha e de UM escopo. Canal e equipe juntos nao teriam significado
        // definido na precedencia.
        DB::statement('alter table business_hours add constraint business_hours_um_escopo check (channel_id is null or team_id is null)');
    }

    public function down(): void
    {
        DB::statement('alter table business_hours drop constraint if exists business_hours_um_escopo');
        DB::statement('drop index if exists business_hours_conta_unica');
        DB::statement('drop index if exists business_hours_canal_unica');
        DB::statement('drop index if exists business_hours_equipe_unica');

        Schema::table('business_hours', function (Blueprint $tabela) {
            $tabela->dropConstrainedForeignId('team_id');
            $tabela->unique(['tenant_id', 'channel_id', 'dia_semana']);
        });
    }
};
