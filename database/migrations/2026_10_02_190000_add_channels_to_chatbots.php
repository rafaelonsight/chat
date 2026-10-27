<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};
return new class extends Migration {
 public function up(): void {
  Schema::table('chatbots', fn (Blueprint $t) => $t->jsonb('channel_ids')->nullable());
  DB::statement('drop index chatbots_um_ativo_por_canal');
  DB::statement('create unique index chatbots_um_ativo_por_canal on chatbots (tenant_id, coalesce(channel_id, 0)) where ativo and channel_ids is null');
 }
 public function down(): void {
  if (DB::table('chatbots')->whereNotNull('channel_ids')->exists()) throw new RuntimeException('Preserve os vínculos multicanal antes de reverter.');
  DB::statement('drop index chatbots_um_ativo_por_canal');
  Schema::table('chatbots', fn (Blueprint $t) => $t->dropColumn('channel_ids'));
  DB::statement('create unique index chatbots_um_ativo_por_canal on chatbots (tenant_id, coalesce(channel_id, 0)) where ativo');
 }
};
