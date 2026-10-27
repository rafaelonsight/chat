<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  Schema::create('agenda_communication_preferences',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id');$t->foreignId('contact_id');$t->boolean('marketing')->default(false);$t->boolean('reminders')->default(false);$t->string('source',240);$t->foreignId('actor_id')->nullable();$t->timestampsTz();$t->unique(['tenant_id','contact_id']);
   $t->foreign(['tenant_id','contact_id'])->references(['tenant_id','id'])->on('contacts')->restrictOnDelete();
   $t->foreign(['tenant_id','actor_id'])->references(['tenant_id','id'])->on('users')->restrictOnDelete();
  });
  foreach(['agenda_reminder_rules','agenda_outreach_campaigns'] as $table)Schema::create($table,function(Blueprint $t)use($table){
   $t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();$t->foreignId('actor_id');$t->foreignId('channel_id');$t->string('name',120);$t->text('body');$t->unsignedInteger('version')->default(1);$t->timestampsTz();$t->unique(['tenant_id','id']);
   $t->foreign(['tenant_id','actor_id'])->references(['tenant_id','id'])->on('users')->restrictOnDelete();
   if($table==='agenda_reminder_rules'){$t->boolean('enabled')->default(false);$t->unsignedInteger('minutes_before')->default(30);$t->string('mode',24)->default('confirmation');}
   else {$t->string('status',24)->default('draft');$t->timestampTz('scheduled_at')->nullable();$t->timestampTz('last_batch_at')->nullable();$t->unsignedInteger('per_minute')->default(5);$t->string('window_start',5)->default('09:00');$t->string('window_end',5)->default('20:00');}
  });
  // Channels/messages already carry globally unique primary IDs. Enforce tenant identity too.
  Schema::table('channels',fn(Blueprint $t)=>$t->unique(['tenant_id','id'],'agenda_outreach_channels_identity'));
  Schema::table('messages',fn(Blueprint $t)=>$t->unique(['tenant_id','id'],'agenda_outreach_messages_identity'));
  foreach(['agenda_reminder_rules','agenda_outreach_campaigns'] as $table)Schema::table($table,fn(Blueprint $t)=>$t->foreign(['tenant_id','channel_id'])->references(['tenant_id','id'])->on('channels')->restrictOnDelete());
  Schema::create('agenda_outreach_deliveries',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id');$t->foreignId('campaign_id')->nullable();$t->foreignId('rule_id')->nullable();$t->unsignedInteger('rule_version')->nullable();$t->foreignId('entry_id')->nullable();$t->unsignedInteger('entry_version')->nullable();$t->foreignId('contact_id');$t->foreignId('channel_id');$t->foreignId('message_id')->nullable();$t->string('dedupe',64);$t->string('state',24)->default('pending');$t->string('reason',240)->nullable();$t->timestampTz('due_at');$t->timestampTz('expires_at');$t->jsonb('reply')->nullable();$t->boolean('consumed')->default(false);$t->timestampsTz();$t->unique(['tenant_id','dedupe']);$t->unique('message_id');$t->index(['state','due_at']);
   foreach(['contact_id'=>'contacts','channel_id'=>'channels','message_id'=>'messages','entry_id'=>'agenda_calendar_entries','campaign_id'=>'agenda_outreach_campaigns','rule_id'=>'agenda_reminder_rules'] as $column=>$target)$t->foreign(['tenant_id',$column])->references(['tenant_id','id'])->on($target)->restrictOnDelete();
  });
  DB::statement("ALTER TABLE agenda_outreach_deliveries ADD CONSTRAINT agenda_outreach_origin CHECK ((campaign_id IS NOT NULL AND rule_id IS NULL AND entry_id IS NULL) OR (campaign_id IS NULL AND rule_id IS NOT NULL AND entry_id IS NOT NULL))");
  Schema::create('agenda_communication_events',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();$t->string('kind',32);$t->unsignedBigInteger('subject_id');$t->foreignId('actor_id')->nullable();$t->jsonb('data')->default('{}');$t->timestampTz('created_at');$t->index(['tenant_id','kind','subject_id']);});
 }
 public function down():void {
  foreach(['agenda_communication_events','agenda_outreach_deliveries','agenda_outreach_campaigns','agenda_reminder_rules','agenda_communication_preferences'] as $table)Schema::dropIfExists($table);
  Schema::table('messages',fn(Blueprint $t)=>$t->dropUnique('agenda_outreach_messages_identity'));Schema::table('channels',fn(Blueprint $t)=>$t->dropUnique('agenda_outreach_channels_identity'));
 }
};
