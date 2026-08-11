<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Os avisos que aparecem no sino do painel.
 *
 * E a tabela padrao de notificacoes do Laravel, e ela entra agora porque a mencao numa nota
 * precisa CHAMAR alguem. Sem aviso, a nota interna era um mural: a pessoa escrevia e rezava
 * para alguem abrir aquela conversa e ler.
 *
 * O tempo real vem de graca: o canal App.Models.User.{id} ja estava autorizado no
 * routes/channels.php para o painel, e o Reverb ja esta de pe. O sino atualiza sozinho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            /*
             * json E NAO text, e esta linha custou uma suite inteira vermelha.
             *
             * A migracao padrao do Laravel cria esta coluna como TEXT, e funciona — para o
             * Laravel, que le e escreve a coluna inteira de uma vez. Mas o Filament conta os
             * avisos nao lidos com data->>'format', que e operador de JSON: no Postgres isso
             * estoura em cima de text.
             *
             * E estoura DENTRO DO TOPO DA PAGINA, onde o sino mora. Ou seja: o tipo errado
             * nesta coluna nao quebra o sino, apaga TODA tela do painel. Cinquenta testes de
             * telas que nada tem a ver com aviso ficaram vermelhos por causa dela.
             */
            $t->json('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();

            // A consulta do sino e sempre "os nao lidos desta pessoa, mais recentes primeiro".
            $t->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
