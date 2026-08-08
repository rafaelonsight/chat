<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A mensagem que o atendente mandou pelo proprio celular.
 *
 * ATE AGORA ELA SUMIA. Todo evento com fromMe era descartado como "eco do que nos mesmos
 * enviamos" — o que e verdade quando a mensagem saiu POR AQUI, e falso quando alguem abriu o
 * WhatsApp no telefone e respondeu de la. O sistema perdia metade da conversa e ninguem via:
 * o cliente perguntava, o atendente respondia pelo celular, e no painel a conversa ficava
 * parada na pergunta.
 *
 * Pior: quem abrisse o atendimento depois concluiria que o cliente estava sem resposta, e
 * responderia de novo.
 *
 * A COLUNA EXISTE PARA A TELA PODER DIZER ISSO. Sem ela, a mensagem apareceria como saida
 * normal sem nome de atendente, e a diferenca entre "o sistema mandou" e "alguem mandou por
 * fora" — que muda o que a equipe deve fazer a seguir — ficaria invisivel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('por_fora')->default(false)->after('automatica');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('por_fora');
        });
    }
};
