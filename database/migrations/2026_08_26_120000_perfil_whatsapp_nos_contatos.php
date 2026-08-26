<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('whatsapp_nome', 120)->nullable()->after('nome');
            $table->string('foto_whatsapp')->nullable()->after('whatsapp_nome');
            $table->timestamp('foto_whatsapp_consultada_em')->nullable()->after('foto_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn([
            'whatsapp_nome', 'foto_whatsapp', 'foto_whatsapp_consultada_em',
        ]));
    }
};
