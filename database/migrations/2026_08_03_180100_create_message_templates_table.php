<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('titulo');
            $table->string('atalho');
            $table->text('corpo');
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            // atalho e como o atendente encontra o modelo: repetido dentro do
            // mesmo tenant seria ambiguo. Entre tenants pode repetir.
            $table->unique(['tenant_id', 'atalho']);
            $table->index(['tenant_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
