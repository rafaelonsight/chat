<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Agenda: compromisso com o cliente, e lembrete pessoal.
 *
 * DUAS COISAS NA MESMA TABELA, e a diferenca e QUEM VE.
 *
 *   compromisso -> tem hora marcada com alguem de fora. A equipe inteira ve, porque quem
 *                  atende o telefone precisa saber que o colega vai la as 14h.
 *   lembrete    -> "cobrar esse cliente amanha". So quem criou ve. Lembrete alheio na tela de
 *                  todo mundo vira ruido, e ruido faz a agenda inteira ser ignorada.
 *
 * Uma tabela e nao duas porque a estrutura e a mesma — o que muda e uma regra de visibilidade,
 * e duas tabelas iguais divergem no primeiro campo novo.
 *
 * O VINCULO COM CONTATO E CONVERSA E OPCIONAL. "Ligar para o contador" nao tem contato
 * cadastrado, e exigir um faria a pessoa inventar cadastro para conseguir anotar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // De quem e o compromisso. Nao e o mesmo que quem criou: da para marcar para o
            // colega, e ai a agenda dele precisa mostrar.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('tipo', 20)->default('compromisso');
            $table->string('titulo', 120);
            $table->text('descricao')->nullable();

            $table->timestamp('comeca_em');
            $table->unsignedSmallInteger('duracao_min')->nullable();
            $table->timestamp('concluido_em')->nullable();

            $table->timestamps();

            // A tela pergunta sempre "o que vem agora": e por aqui que ela procura.
            $table->index(['tenant_id', 'comeca_em']);
        });

        DB::statement("alter table appointments add constraint appointments_tipo_check
                       check (tipo in ('compromisso','lembrete'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
