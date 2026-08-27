<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('board_item_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tipo', 20)->default('mensagem');
            $table->text('corpo');
            $table->jsonb('mencoes')->nullable();
            $table->timestamps();
            $table->index(['board_item_id', 'id']);
        });
    }

    public function down(): void { Schema::dropIfExists('board_item_updates'); }
};
