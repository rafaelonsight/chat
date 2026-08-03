<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // caminho no disco PRIVADO. Midia de WhatsApp e conteudo de cliente:
            // documento, comprovante, print com dados. Nunca em public/.
            $table->string('media_path')->nullable()->after('corpo');
            $table->string('media_mime')->nullable()->after('media_path');
            $table->string('media_nome')->nullable()->after('media_mime');
            $table->unsignedBigInteger('media_tamanho')->nullable()->after('media_nome');
            $table->unsignedInteger('media_duracao')->nullable()->after('media_tamanho');
            $table->text('legenda')->nullable()->after('media_duracao');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime', 'media_nome', 'media_tamanho', 'media_duracao', 'legenda']);
        });
    }
};
