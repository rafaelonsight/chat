<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('ai_agents',fn(Blueprint $t)=>$t->json('ferramentas')->nullable());
  Schema::table('attendance_actions',fn(Blueprint $t)=>$t->boolean('ia_consulta')->default(false));
  Schema::create('ai_tool_runs',function(Blueprint $t){$t->id();$t->string('chave',64)->unique();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('ai_agent_id')->constrained()->cascadeOnDelete();$t->foreignId('attendance_action_id')->constrained()->cascadeOnDelete();$t->foreignId('conversation_id')->constrained()->cascadeOnDelete();$t->foreignId('message_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('status',20);$t->string('config_hash',64);$t->text('resultado')->nullable();$t->timestamps();$t->index(['tenant_id','conversation_id','status']);});
 }
 public function down():void {Schema::dropIfExists('ai_tool_runs');Schema::table('attendance_actions',fn(Blueprint $t)=>$t->dropColumn('ia_consulta'));Schema::table('ai_agents',fn(Blueprint $t)=>$t->dropColumn('ferramentas'));}
};
