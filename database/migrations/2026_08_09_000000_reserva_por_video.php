<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * O link de agendamento pode marcar reuniao por video.
 *
 * Fica na PAGINA e nao em cada reserva porque e a natureza do compromisso que aquele link
 * marca: "Consulta online, 30min" e por video sempre, e "Visita tecnica" nunca. Perguntar ao
 * cliente seria empurrar para ele uma decisao que nao e dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pages', function (Blueprint $table) {
            $table->boolean('por_video')->default(false)->after('local');
        });
    }

    public function down(): void
    {
        Schema::table('booking_pages', function (Blueprint $table) {
            $table->dropColumn('por_video');
        });
    }
};
