<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::table('users',function(Blueprint $t){
   $t->string('agenda_access_status',32)->default('ativo');
   $t->unsignedBigInteger('agenda_access_version')->default(1);
   $t->unique(['tenant_id','id'],'agenda_users_tenant_identity');
  });
  DB::statement('CREATE UNIQUE INDEX agenda_users_email_lower_unique ON users (lower(email))');
  Schema::table('agenda_professionals',function(Blueprint $t){
   $t->unsignedBigInteger('user_id')->nullable()->unique();
   $t->foreign(['tenant_id','user_id'])->references(['tenant_id','id'])->on('users')->restrictOnDelete();
  });
  DB::statement("ALTER TABLE users ADD CONSTRAINT agenda_access_status_values CHECK (agenda_access_status IN ('ativo','convite_pendente','redefinicao_obrigatoria','suspenso'))");
  Schema::create('agenda_access_events',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();
   $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
   $t->foreignId('user_id')->constrained()->restrictOnDelete();
   $t->foreignId('professional_id')->nullable()->constrained('agenda_professionals')->restrictOnDelete();
   $t->string('action',50);$t->jsonb('metadata')->nullable();$t->timestampTz('created_at')->useCurrent();
   $t->index(['tenant_id','user_id','created_at']);
  });
  Schema::create('agenda_access_deliveries',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id')->constrained()->restrictOnDelete();
   $t->foreignId('user_id')->constrained()->restrictOnDelete();
   $t->foreignId('professional_id')->constrained('agenda_professionals')->restrictOnDelete();
   $t->string('purpose',30);$t->string('status',20)->default('pendente');
   $t->unsignedBigInteger('access_version');$t->string('failure_code',40)->nullable();
   $t->timestampTz('accepted_at')->nullable();$t->timestampsTz();
   $t->index(['tenant_id','professional_id','id']);
  });
 }
 public function down(): void {
  Schema::dropIfExists('agenda_access_deliveries');Schema::dropIfExists('agenda_access_events');
  Schema::table('agenda_professionals',fn(Blueprint $t)=>$t->dropForeign(['tenant_id','user_id']));
  Schema::table('agenda_professionals',fn(Blueprint $t)=>$t->dropColumn('user_id'));
  DB::statement('DROP INDEX agenda_users_email_lower_unique');
  DB::statement('ALTER TABLE users DROP CONSTRAINT agenda_access_status_values');
  Schema::table('users',function(Blueprint $t){$t->dropUnique('agenda_users_tenant_identity');$t->dropColumn(['agenda_access_status','agenda_access_version']);});
 }
};
