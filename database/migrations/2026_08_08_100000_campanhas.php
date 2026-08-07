<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Campanhas: mandar a mesma mensagem para muita gente.
 *
 * TRES DECISOES ESTAO GRAVADAS AQUI, e todas existem para proteger o numero do cliente.
 *
 * 1. OPT-OUT NO CONTATO, e nao na campanha. Quem pediu para sair pediu para a EMPRESA, nao
 *    para aquele disparo. Guardar por campanha faria a proxima recomecar do zero e a pessoa
 *    ter de pedir de novo — que e como se perde numero e se ganha denuncia.
 *
 * 2. UMA LINHA POR DESTINATARIO. Sem isso nao da para responder "quem recebeu?" nem retomar
 *    de onde parou quando o envio for interrompido; e disparo interrompido que reinicia do
 *    comeco manda tudo duas vezes.
 *
 * 3. O RITMO E DA CAMPANHA. Nao e configuracao global escondida: quem dispara precisa ver e
 *    poder baixar. Disparo em rajada e o gatilho mais rapido de banimento no canal por QR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('opt_out_em')->nullable();
            $table->string('opt_out_motivo', 40)->nullable();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('nome');
            $table->string('status', 20)->default('rascunho');

            // O publico: etiqueta de contato, ou todos. Guardado como escolha e nao como lista
            // pronta, para a tela poder dizer quantos sao ANTES de disparar.
            $table->string('publico', 20)->default('etiqueta');
            $table->foreignId('tag_id')->nullable()->constrained('tags')->nullOnDelete();

            // Texto livre (canal por QR) ou template aprovado (canal oficial).
            $table->text('corpo')->nullable();
            $table->foreignId('meta_template_id')->nullable()->constrained('meta_templates')->nullOnDelete();
            $table->jsonb('template_valores')->nullable();

            $table->timestamp('agendada_para')->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('concluida_em')->nullable();

            // Ritmo e janela: as duas travas contra queimar o numero.
            $table->unsignedSmallInteger('por_minuto')->default(6);
            $table->unsignedTinyInteger('hora_inicio')->default(9);
            $table->unsignedTinyInteger('hora_fim')->default(20);

            $table->timestamps();
        });

        DB::statement("alter table campaigns add constraint campaigns_status_check
                       check (status in ('rascunho','agendada','enviando','pausada','concluida','cancelada'))");

        DB::statement("alter table campaigns add constraint campaigns_publico_check
                       check (publico in ('etiqueta','todos'))");

        // Teto duro no banco, e nao so na tela: 30 por minuto ja e agressivo para um numero
        // pessoal, e o campo chega do navegador.
        DB::statement('alter table campaigns add constraint campaigns_ritmo_check
                       check (por_minuto between 1 and 30)');

        DB::statement('alter table campaigns add constraint campaigns_janela_check
                       check (hora_inicio < hora_fim)');

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->string('status', 20)->default('pendente');
            $table->string('motivo')->nullable();
            $table->timestamp('enviada_em')->nullable();

            $table->timestamps();

            // Ninguem recebe a mesma campanha duas vezes, nem que o disparo seja reiniciado.
            $table->unique(['campaign_id', 'contact_id']);
        });

        DB::statement("alter table campaign_recipients add constraint campaign_recipients_status_check
                       check (status in ('pendente','enviada','falhou','pulada'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['opt_out_em', 'opt_out_motivo']);
        });
    }
};
