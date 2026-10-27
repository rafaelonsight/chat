<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('agenda_flow_settings',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();$t->unsignedBigInteger('channel_id')->unique();$t->unsignedBigInteger('actor_id');$t->boolean('enabled')->default(false);$t->string('business_name',120);$t->string('greeting',600);$t->string('address',240)->nullable();$t->string('handoff',400);$t->timestampsTz();$t->foreign(['tenant_id','channel_id'])->references(['tenant_id','id'])->on('channels')->restrictOnDelete();$t->foreign(['tenant_id','actor_id'])->references(['tenant_id','id'])->on('users')->restrictOnDelete();});
  Schema::create('agenda_flow_sessions',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();$t->foreignId('conversation_id')->unique()->constrained()->restrictOnDelete();$t->string('step',24)->default('menu');$t->jsonb('data')->default('{}');$t->timestampTz('expires_at')->nullable();$t->timestampsTz();});
  Schema::create('agenda_flow_inputs',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();$t->foreignId('message_id')->unique()->constrained()->restrictOnDelete();$t->foreignId('output_id')->nullable()->constrained('messages')->restrictOnDelete();$t->timestampTz('created_at');});
 }
 public function down():void {Schema::dropIfExists('agenda_flow_inputs');Schema::dropIfExists('agenda_flow_sessions');Schema::dropIfExists('agenda_flow_settings');}
};
