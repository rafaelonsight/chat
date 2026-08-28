<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('board_item_updates', function (Blueprint $t) {
            $t->foreignId('reply_to_id')->nullable()->constrained('board_item_updates')->nullOnDelete();
            $t->timestamp('edited_at')->nullable();
        });
        Schema::table('board_items', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->constrained('board_items')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->json('checklist')->nullable();
        });
        Schema::table('funnels', function (Blueprint $t) {
            $t->text('descricao')->nullable();
            $t->timestamp('arquivado_em')->nullable();
        });
        Schema::create('board_dependencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('depends_on_id')->constrained('board_items')->cascadeOnDelete();
            $t->unique(['board_item_id', 'depends_on_id']);
        });
        Schema::create('board_watchers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unique(['board_item_id', 'user_id']);
        });
        Schema::create('board_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('path');
            $t->string('name');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('board_item_updates', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reply_to_id');
            $t->dropColumn('edited_at');
        });
        Schema::dropIfExists('board_attachments');
        Schema::dropIfExists('board_watchers');
        Schema::dropIfExists('board_dependencies');
        Schema::table('board_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['version', 'checklist']);
        });
        Schema::table('funnels', fn (Blueprint $t) => $t->dropColumn(['descricao', 'arquivado_em']));
    }
};
