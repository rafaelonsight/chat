<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('attendance_actions',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->string('nome',80);$t->string('descricao',240)->nullable();$t->string('tipo',20);$t->text('url')->nullable();$t->foreignId('chatbot_integration_id')->nullable()->constrained()->nullOnDelete();$t->text('dados')->nullable();$t->json('perfis');$t->boolean('ativa')->default(true);$t->unsignedInteger('ordem')->default(0);$t->timestamps();$t->index(['tenant_id','ativa']);
  });
  Schema::create('attendance_action_runs',function(Blueprint $t){
   $t->id();$t->uuid('chave')->unique();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('attendance_action_id')->constrained()->cascadeOnDelete();$t->foreignId('conversation_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('status',20);$t->timestamps();$t->index(['tenant_id','created_at']);
  });
 }
 public function down(): void {Schema::dropIfExists('attendance_action_runs');Schema::dropIfExists('attendance_actions');}
};
