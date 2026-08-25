<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_item_tag', function (Blueprint $table) {
            $table->foreignId('board_item_id')->constrained('board_items')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origem', 20)->default('manual');
            $table->timestamp('created_at')->nullable();

            $table->primary(['board_item_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_item_tag');
    }
};
