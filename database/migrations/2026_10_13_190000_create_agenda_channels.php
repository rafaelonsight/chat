<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void {
  Schema::create('agenda_channel_connections',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();$t->uuid('public_id')->unique();$t->string('type',24);$t->string('name',120);$t->string('external_id',128)->nullable();$t->string('business_id',128)->nullable();$t->text('credentials')->nullable();$t->string('status',24)->default('configurado');$t->unsignedInteger('version')->default(1);$t->timestampTz('validated_at')->nullable();$t->timestampTz('received_at')->nullable();$t->string('failure_code',64)->nullable();$t->timestampsTz();
   $t->unique(['tenant_id','type']);$t->unique(['type','external_id']);$t->unique(['tenant_id','id']);
  });
  DB::statement("ALTER TABLE agenda_channel_connections ADD CONSTRAINT agenda_channel_type CHECK(type IN ('meta_cloud','evolution','instagram','messenger'))");
  DB::statement("ALTER TABLE agenda_channel_connections ADD CONSTRAINT agenda_channel_status CHECK(status IN ('configurado','validado','desconectado','falha'))");
  Schema::create('agenda_plan_entitlements',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();$t->unsignedInteger('extra_users')->default(0);$t->string('reference',120)->nullable();$t->timestampsTz();});
  DB::statement('ALTER TABLE agenda_plan_entitlements ADD CONSTRAINT agenda_extra_users CHECK(extra_users BETWEEN 0 AND 1000)');
  Schema::create('agenda_channel_events',function(Blueprint $t){$t->id();$t->foreignId('tenant_id');$t->foreignId('connection_id');$t->foreignId('actor_id')->nullable();$t->string('action',32);$t->timestampTz('created_at');$t->foreign(['tenant_id','connection_id'])->references(['tenant_id','id'])->on('agenda_channel_connections')->restrictOnDelete();$t->foreign(['tenant_id','actor_id'])->references(['tenant_id','id'])->on('users')->restrictOnDelete();});
 }
 public function down():void {Schema::dropIfExists('agenda_channel_events');Schema::dropIfExists('agenda_plan_entitlements');Schema::dropIfExists('agenda_channel_connections');}
};
