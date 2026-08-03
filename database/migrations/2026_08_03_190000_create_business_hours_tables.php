<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // nulo = grade da conta; preenchido = grade propria daquele canal
            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_semana'); // 0 = domingo
            $table->boolean('ativo')->default(true);
            // Lista de intervalos, nao quatro colunas fixas: a tela mostra
            // inicio/almoco/fim porque cobre 95% dos casos, mas o modelo aceita
            // dia sem almoco, plantao que cruza a meia-noite e turno triplo sem
            // precisar de migration nova.
            $table->jsonb('intervalos')->default('[]');
            $table->timestamps();

            $table->unique(['tenant_id', 'channel_id', 'dia_semana']);
        });

        Schema::create('business_hour_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('data');
            $table->boolean('fechado')->default(true);
            $table->jsonb('intervalos')->nullable();
            $table->string('descricao')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'data']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('resposta_automatica_ativa')->default(false)->after('fuso_horario');
            $table->text('resposta_automatica_texto')->nullable()->after('resposta_automatica_ativa');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Sem esta marca a resposta automatica moveria a conversa para "em
            // atendimento" e ela sairia de Novos — o cliente esperaria a noite
            // inteira e de manha ninguem veria.
            $table->boolean('automatica')->default(false)->after('direcao');
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('automatica'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['resposta_automatica_ativa', 'resposta_automatica_texto']));
        Schema::dropIfExists('business_hour_exceptions');
        Schema::dropIfExists('business_hours');
    }
};
