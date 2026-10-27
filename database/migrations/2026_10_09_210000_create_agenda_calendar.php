<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
 public function up(): void {
  DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
  Schema::create('agenda_settings',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
   $t->string('timezone',64)->default('America/Sao_Paulo');$t->unsignedInteger('min_notice_minutes')->default(60);
   $t->unsignedInteger('horizon_days')->default(90);$t->unsignedInteger('cancel_notice_minutes')->default(1440);
   $t->unsignedInteger('slot_minutes')->default(15);$t->boolean('professional_manage')->default(false);$t->timestampsTz();
  });
  DB::statement('ALTER TABLE agenda_settings ADD CONSTRAINT agenda_settings_limits CHECK (min_notice_minutes BETWEEN 0 AND 43200 AND horizon_days BETWEEN 1 AND 365 AND cancel_notice_minutes BETWEEN 0 AND 43200 AND slot_minutes BETWEEN 5 AND 120)');
  Schema::create('agenda_work_hours',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id');$t->foreignId('professional_id');$t->smallInteger('weekday');$t->smallInteger('start_minute');$t->smallInteger('end_minute');$t->timestampsTz();
   $t->foreign(['tenant_id','professional_id'])->references(['tenant_id','id'])->on('agenda_professionals')->restrictOnDelete();
   $t->index(['tenant_id','professional_id','weekday']);
  });
  DB::statement('ALTER TABLE agenda_work_hours ADD CONSTRAINT agenda_hours_range CHECK (weekday BETWEEN 0 AND 6 AND start_minute >= 0 AND end_minute <= 1440 AND end_minute > start_minute)');
  DB::statement('ALTER TABLE agenda_work_hours ADD CONSTRAINT agenda_hours_no_overlap EXCLUDE USING gist (tenant_id WITH =, professional_id WITH =, weekday WITH =, int4range(start_minute::integer,end_minute::integer,\'[)\') WITH &&)');
  Schema::table('contacts',fn(Blueprint $t)=>$t->unique(['tenant_id','id'],'agenda_contacts_tenant_identity'));
  Schema::create('agenda_calendar_entries',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id');$t->foreignId('professional_id');$t->foreignId('service_id')->nullable();$t->foreignId('contact_id')->nullable();$t->foreignId('created_by')->nullable();
   $t->string('kind',16);$t->string('status',24);$t->string('presence',16)->default('pendente');$t->boolean('active')->default(true);
   $t->timestampTz('start_at');$t->timestampTz('end_at');$t->timestampTz('occupied_until');
   $t->decimal('price',12,2)->default(0);$t->unsignedInteger('duration_minutes')->default(0);$t->unsignedInteger('buffer_minutes')->default(0);
   $t->jsonb('snapshot')->default('{}');$t->string('reason',200)->nullable();$t->string('source',16)->default('manual');
   $t->uuid('request_key')->nullable();$t->string('request_fingerprint',64)->nullable();$t->unsignedInteger('version')->default(1);$t->timestampsTz();
   $t->unique(['tenant_id','id'],'agenda_entries_tenant_identity');$t->unique(['tenant_id','source','request_key'],'agenda_entries_request_unique');
   $t->index(['tenant_id','professional_id','start_at']);$t->index(['tenant_id','contact_id','start_at']);
   $t->foreign(['tenant_id','professional_id'])->references(['tenant_id','id'])->on('agenda_professionals')->restrictOnDelete();
   $t->foreign(['tenant_id','service_id'])->references(['tenant_id','id'])->on('agenda_services')->restrictOnDelete();
   $t->foreign(['tenant_id','contact_id'])->references(['tenant_id','id'])->on('contacts')->restrictOnDelete();
   $t->foreign(['tenant_id','created_by'])->references(['tenant_id','id'])->on('users')->restrictOnDelete();
  });
  DB::statement("ALTER TABLE agenda_calendar_entries ADD CONSTRAINT agenda_entry_valid CHECK (version > 0 AND start_at < end_at AND occupied_until >= end_at AND price >= 0 AND presence IN ('pendente','confirmada','recusada') AND source IN ('manual','whatsapp') AND ((kind='booking' AND service_id IS NOT NULL AND contact_id IS NOT NULL AND duration_minutes BETWEEN 1 AND 1440 AND buffer_minutes BETWEEN 0 AND 1440 AND end_at=start_at+duration_minutes*interval '1 minute' AND occupied_until=end_at+buffer_minutes*interval '1 minute' AND status IN ('confirmado','em_atendimento','concluido','cancelado','falta')) OR (kind='block' AND service_id IS NULL AND contact_id IS NULL AND occupied_until=end_at AND status IN ('bloqueado','cancelado'))) AND active=(status<>'cancelado'))");
  DB::statement("ALTER TABLE agenda_calendar_entries ADD CONSTRAINT agenda_calendar_no_overlap EXCLUDE USING gist (tenant_id WITH =, professional_id WITH =, tstzrange(start_at,occupied_until,'[)') WITH &&) WHERE (active)");
  Schema::create('agenda_calendar_events',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id');$t->foreignId('entry_id');$t->foreignId('actor_id')->nullable();$t->string('action',32);$t->unsignedInteger('version');$t->jsonb('metadata')->default('{}');$t->timestampTz('created_at');
   $t->foreign(['tenant_id','entry_id'])->references(['tenant_id','id'])->on('agenda_calendar_entries')->restrictOnDelete();
   $t->foreign(['tenant_id','actor_id'])->references(['tenant_id','id'])->on('users')->restrictOnDelete();
   $t->index(['tenant_id','entry_id','created_at']);
  });
 }
 public function down(): void {
  Schema::dropIfExists('agenda_calendar_events');Schema::dropIfExists('agenda_calendar_entries');Schema::table('contacts',fn(Blueprint $t)=>$t->dropUnique('agenda_contacts_tenant_identity'));Schema::dropIfExists('agenda_work_hours');Schema::dropIfExists('agenda_settings');
 }
};
