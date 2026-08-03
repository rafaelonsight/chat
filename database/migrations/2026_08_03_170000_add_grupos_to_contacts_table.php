<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // O JID e a identidade real no WhatsApp: pessoa tem telefone, grupo
            // nao tem. Passa a ser ele a chave, e nao o telefone.
            $table->string('jid')->nullable()->after('tenant_id');
            $table->string('tipo')->default('pessoa')->after('jid');
        });

        // contatos existentes: monta o JID a partir do telefone
        DB::statement("
            update contacts
               set jid = replace(telefone_e164, '+', '') || '@s.whatsapp.net'
             where jid is null and telefone_e164 is not null
        ");

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'telefone_e164']);
        });

        // grupo nao tem telefone
        DB::statement('alter table contacts alter column telefone_e164 drop not null');
        DB::statement('alter table contacts alter column jid set not null');

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'jid']);
            $table->index(['tenant_id', 'tipo']);
        });

        Schema::table('messages', function (Blueprint $table) {
            // Em grupo importa quem falou, nao so de onde veio.
            $table->string('remetente_nome')->nullable()->after('direcao');
            $table->string('remetente_jid')->nullable()->after('remetente_nome');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['remetente_nome', 'remetente_jid']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'tipo']);
            $table->dropUnique(['tenant_id', 'jid']);
        });

        DB::statement('delete from contacts where telefone_e164 is null');
        DB::statement('alter table contacts alter column telefone_e164 set not null');

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'telefone_e164']);
            $table->dropColumn(['jid', 'tipo']);
        });
    }
};
