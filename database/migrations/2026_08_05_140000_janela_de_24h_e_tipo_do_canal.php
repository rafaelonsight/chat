<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 da integracao Meta: a janela de 24 horas passa a existir no modelo.
 *
 * Na API oficial do WhatsApp, mensagem de texto livre so pode sair dentro de 24h
 * desde a ULTIMA mensagem do cliente. Fora dela, so template aprovado. No Baileys
 * essa regra nao existe — e por isso a janela e propriedade do TIPO do canal, nao do
 * sistema: mostrar a restricao onde ela nao vale seria inventar limite.
 *
 * A coluna channels.tipo ja existia e nunca foi lida. Ganha significado e trava aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            // Quando o CLIENTE falou por ultimo. Denormalizado de proposito: a lista
            // do atendimento mostra 30 conversas por pagina, e calcular o maximo das
            // mensagens de cada uma seria 30 subconsultas por render.
            $t->timestamp('ultima_entrada_em')->nullable();
        });

        // Conversa antiga tem historico: sem o preenchimento, todas apareceriam como
        // "cliente nunca falou" e a janela nasceria fechada para todo mundo.
        DB::statement("
            update conversations c
            set ultima_entrada_em = (
                select max(m.created_at) from messages m
                where m.conversation_id = c.id and m.direcao = 'in'
            )
        ");

        // Tipo invalido nao chega a virar bug de comportamento: vira erro na hora de
        // gravar. Enum do Postgres exigiria ALTER TYPE para crescer; CHECK nao.
        DB::statement("
            alter table channels add constraint channels_tipo_valido
            check (tipo in ('evolution', 'meta_cloud'))
        ");

        Schema::table('conversations', function (Blueprint $t) {
            // Para achar quem esta com a janela perto de fechar.
            $t->index(['tenant_id', 'ultima_entrada_em'], 'conversations_janela_index');
        });
    }

    public function down(): void
    {
        DB::statement('alter table channels drop constraint if exists channels_tipo_valido');

        Schema::table('conversations', function (Blueprint $t) {
            $t->dropIndex('conversations_janela_index');
            $t->dropColumn('ultima_entrada_em');
        });
    }
};
