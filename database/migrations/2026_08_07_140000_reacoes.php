<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Reacao com emoji, guardada na propria mensagem.
 *
 * Duas colunas e nao uma tabela de reacoes: no WhatsApp de empresa existem exatamente DOIS
 * lados, o cliente e nos, e cada lado tem no maximo uma reacao por mensagem — reagir de novo
 * substitui a anterior. Uma tabela ligada existiria para permitir muitos reagentes, que e um
 * caso que este produto nao tem, e cobraria um JOIN em toda a listagem de conversa.
 *
 * Se um dia a conversa em grupo precisar de varias reacoes por mensagem, ai sim vira tabela.
 * Ate la, isto e o suficiente e e mais rapido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // 16 e nao 4: emoji com tom de pele ou bandeira ocupa varios pontos de codigo.
            $table->string('reacao_cliente', 16)->nullable()->after('lida_em');
            $table->string('reacao_nossa', 16)->nullable()->after('reacao_cliente');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['reacao_cliente', 'reacao_nossa']);
        });
    }
};
