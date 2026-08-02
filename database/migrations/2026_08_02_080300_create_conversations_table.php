<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamp('ultima_msg_em')->nullable();
            $table->unsignedInteger('nao_lidas')->default(0);
            $table->timestamps();

            $table->unique(['channel_id', 'contact_id']);
            // e literalmente a consulta que a tela do inbox faz o tempo todo
            $table->index(['tenant_id', 'ultima_msg_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
