<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('evento')->nullable();
            $table->jsonb('payload');
            $table->timestamp('recebido_em');
            $table->timestamp('processado_em')->nullable();
            $table->text('erro')->nullable();

            $table->index(['channel_id', 'processado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
