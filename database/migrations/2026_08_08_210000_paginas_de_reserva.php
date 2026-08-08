<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Pagina de reserva: o cliente escolhe o horario sozinho.
 *
 * O QUE ISSO TIRA DO CAMINHO e o vaivem. "Que horas voce pode?" / "terca as 14?" / "nao, so
 * depois das 16" consome quatro mensagens e meia hora para marcar uma visita de trinta
 * minutos. Com o link, quem marca ve so o que existe de verdade.
 *
 * O SLUG E UNICO NO MUNDO, e nao por conta: a URL que o cliente recebe nao tem tenant nenhum
 * dentro dela, entao e o slug sozinho que precisa dizer de quem e a agenda.
 *
 * A DISPONIBILIDADE E JSON de propósito. Sao faixas por dia da semana — "segunda das 9 as 12 e
 * das 13 as 18" — e isso nunca e consultado por SQL: sempre se le a pagina inteira para
 * calcular as vagas. Tabela filha aqui daria join e migration para toda mudanca de formato,
 * sem nenhuma consulta em troca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // De quem e a agenda que vai encher. Nao e quem criou a pagina: da para a recepcao
            // montar o link do tecnico.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Por qual numero confirmar no WhatsApp. Nulo nao confirma nada.
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();

            $table->string('slug', 60)->unique();
            $table->string('titulo', 120);
            $table->text('descricao')->nullable();
            $table->string('local', 160)->nullable();

            $table->unsignedSmallInteger('duracao_min')->default(30);

            // Folga antes e depois de cada compromisso: quem sai de uma visita nao teleporta
            // para a proxima.
            $table->unsignedSmallInteger('intervalo_min')->default(0);

            // Antecedencia minima. Sem ela o cliente marca para daqui a dez minutos e a pessoa
            // descobre quando ja passou.
            $table->unsignedSmallInteger('antecedencia_horas')->default(2);

            $table->unsignedSmallInteger('janela_dias')->default(30);

            // Teto por dia, para o link nao lotar a agenda inteira de um dia so.
            $table->unsignedSmallInteger('limite_dia')->nullable();

            $table->jsonb('disponibilidade')->default('[]');
            $table->boolean('ativa')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'ativa']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('booking_page_id')->nullable()->after('conversation_id')
                ->constrained('booking_pages')->nullOnDelete();
        });

        /*
         * DOIS CLIENTES NO MESMO SEGUNDO.
         *
         * Os dois veem a vaga das 14h livre, os dois confirmam, e a conferencia em PHP diz sim
         * para ambos porque nenhum dos dois estava gravado quando o outro perguntou. So o
         * banco resolve isso, e o segundo INSERT tem de morrer.
         *
         * Parcial: vale so para o que veio de pagina de reserva. Marcar duas coisas na mesma
         * hora a mao e problema de quem marcou, e as vezes e proposital.
         */
        DB::statement('create unique index appointments_vaga_unica
                       on appointments (user_id, comeca_em)
                       where booking_page_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists appointments_vaga_unica');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_page_id');
        });

        Schema::dropIfExists('booking_pages');
    }
};
