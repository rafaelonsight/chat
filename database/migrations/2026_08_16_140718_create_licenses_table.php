<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A licenca de acesso de cada tenant ao produto.
 *
 * Ate aqui, todo tenant criado tinha acesso permanente: nao havia nada que bloqueasse por
 * inadimplencia ou cancelamento. O campo que parecia licenca (`assinatura_ativa`, na tabela
 * tenants) e na verdade um toggle de assinatura de MENSAGEM (o atendente assina "-- Fulano"),
 * sem relacao com acesso — daqui pra frente a licenca de verdade mora aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $t) {
            $t->id();

            // Uma licenca por tenant, por ora: nao ha caso de uso para historico de licencas
            // encerradas e reabertas ainda, e criar isso especulativamente seria abstracao sem
            // uso.
            $t->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // O CHECK do status vem depois do create, no ALTER TABLE la embaixo: e a convencao
            // do projeto (CHECK em vez de enum, porque alterar enum no Postgres e cirurgia) —
            // ver database/migrations/2026_08_11_170000_propostas.php.
            $t->string('status', 16)->default('trial');

            $t->string('plano', 60)->nullable();

            $t->timestamp('inicia_em')->nullable();
            $t->timestamp('vence_em')->nullable();

            // Por que foi suspensa ou cancelada — para quem olha o painel de revenda depois
            // nao precisar perguntar para quem mudou.
            $t->text('motivo')->nullable();

            $t->foreignId('alterada_por')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            $t->index('status');
        });

        DB::statement(
            "ALTER TABLE licenses ADD CONSTRAINT licenses_status_check
             CHECK (status IN ('trial','ativa','em_atraso','suspensa','cancelada'))"
        );

        // Conta que ja existia antes desta tabela nao e trial: ja e cliente. Sem isto, o
        // middleware trata "sem licenca" como valido (de proposito — ver LicencaValida), mas
        // a conta apareceria AUSENTE no painel de revenda, e ausente nao e a mesma coisa que
        // ativo: um dia vira "cadeia sem licenca" e ninguem lembra que aquela conta e valida
        // por ser antiga.
        $agora = now();

        $linhas = DB::table('tenants')->pluck('created_at', 'id')
            ->map(fn ($criadoEm, $id) => [
                'tenant_id'  => $id,
                'status'     => 'ativa',
                'inicia_em'  => $criadoEm ?? $agora,
                'created_at' => $agora,
                'updated_at' => $agora,
            ])
            ->values()
            ->all();

        if ($linhas !== []) {
            DB::table('licenses')->insert($linhas);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
