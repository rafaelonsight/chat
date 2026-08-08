<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Quem foi convidado para um compromisso.
 *
 * O COMPROMISSO JA TINHA UM contact_id, e ele nao serve para isto: aquele campo responde "com
 * quem e a reuniao", e e um so. Convidado e outra coisa — sao varios, cada um recebe o convite
 * pelo seu caminho, e cada um recebe (ou nao) em momentos diferentes. Enfiar isso numa lista
 * dentro do compromisso deixaria "avisar so quem ainda nao foi avisado" impossivel de
 * responder.
 *
 * CONVIDADO PODE NAO SER CONTATO CADASTRADO. O socio que so tem e-mail, o fornecedor que entra
 * uma vez: exigir cadastro faria a pessoa inventar contato para conseguir convidar. Por isso
 * contact_id e opcional e o nome fica gravado aqui.
 *
 * AS DUAS DATAS SAO SEPARADAS de proposito. Mandei por e-mail e depois avisei no WhatsApp sao
 * dois fatos, e um nao apaga o outro — "ja avisei essa pessoa?" precisa saber por onde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();

            // Nulo e convidado de fora do CRM.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->string('nome', 80);
            $table->string('email', 160)->nullable();

            $table->timestamp('email_em')->nullable();
            $table->timestamp('whatsapp_em')->nullable();

            $table->timestamps();

            $table->index(['appointment_id']);
            $table->index(['tenant_id']);
        });

        /*
         * O MESMO CONVIDADO NAO ENTRA DUAS VEZES.
         *
         * Parciais porque as duas identidades sao opcionais: quem tem contato e identificado
         * pelo contato, quem so tem e-mail e identificado pelo e-mail em minusculas — senao
         * "Joana@x.com" e "joana@x.com" viram duas pessoas e recebem dois convites.
         */
        DB::statement('create unique index appointment_guests_contato_unico
                       on appointment_guests (appointment_id, contact_id)
                       where contact_id is not null');

        DB::statement('create unique index appointment_guests_email_unico
                       on appointment_guests (appointment_id, lower(email))
                       where email is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_guests');
    }
};
