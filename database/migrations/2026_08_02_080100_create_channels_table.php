<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('tipo')->default('evolution');
            $table->string('nome');
            // nullable: so pode ser montado depois que o id existe (ver o model)
            $table->string('instance_name')->nullable()->unique();
            $table->string('webhook_secret', 64);
            $table->string('telefone_e164')->nullable();
            $table->string('status')->default('desconectado');
            $table->timestamp('conectado_em')->nullable();
            $table->text('ultimo_erro')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
