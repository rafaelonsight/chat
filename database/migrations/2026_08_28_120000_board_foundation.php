<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnels', function (Blueprint $table) {
            $table->boolean('restrito')->default(false);
        });
        Schema::create('board_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funnel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('papel', 20);
            $table->timestamps();
            $table->unique(['funnel_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        Schema::create('board_item_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['board_item_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
        // Query builder deliberately avoids model events and customer notifications.
        DB::table('board_items as i')->join('users as u', 'u.id', '=', 'i.responsavel_id')
            ->whereColumn('u.tenant_id', 'i.tenant_id')
            ->select(['i.id', 'i.tenant_id', 'i.responsavel_id'])->orderBy('i.id')
            ->chunkById(500, function ($items) {
                foreach ($items as $item) {
                    DB::table('board_item_assignees')->insertOrIgnore([
                        'tenant_id' => $item->tenant_id, 'board_item_id' => $item->id,
                        'user_id' => $item->responsavel_id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }, 'i.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('board_item_assignees');
        Schema::dropIfExists('board_members');
        Schema::table('funnels', fn (Blueprint $table) => $table->dropColumn('restrito'));
    }
};
