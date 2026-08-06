<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credencial da Meta POR CANAL, e nao por servidor.
 *
 * O token vivia so no .env: um para todo o sistema. Isso funciona enquanto o numero
 * oficial e nosso, e para de funcionar no primeiro cliente que traz o proprio numero —
 * porque no caminho do Cadastro Incorporado e o CLIENTE que autoriza, e a Meta emite um
 * token para a WABA dele, nao para nos.
 *
 * O token do .env continua existindo como RESERVA: o canal que ja funciona hoje nao pode
 * parar no meio da transicao.
 *
 * text e nao string: o token vem cifrado no banco, e cifra cresce o texto. Um varchar(255)
 * cortaria silenciosamente e o sintoma seria "a Meta recusa a credencial".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $tabela) {
            $tabela->text('meta_token')->nullable()->after('meta_waba_id');
            $tabela->string('meta_business_id')->nullable()->after('meta_token');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $tabela) {
            $tabela->dropColumn(['meta_token', 'meta_business_id']);
        });
    }
};
