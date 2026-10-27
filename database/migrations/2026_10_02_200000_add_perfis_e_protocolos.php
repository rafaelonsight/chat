<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::table('users',function(Blueprint $t){$t->string('perfil',20)->default('atendente');$t->boolean('ver_notas_internas')->default(false);});
  DB::table('users')->where('admin',true)->update(['perfil'=>'admin','ver_notas_internas'=>true]);
  DB::table('users')->where('admin',false)->update(['ver_notas_internas'=>true]);
  Schema::table('conversations',function(Blueprint $t){$t->string('protocolo',40)->nullable()->unique();});
  DB::statement("UPDATE conversations SET protocolo = 'VRT-' || to_char(created_at, 'YYYYMMDD') || '-' || lpad(id::text, greatest(6,length(id::text)), '0') WHERE protocolo IS NULL");
  Schema::create('encerramentos_atendimento',function(Blueprint $t){
   $t->id();$t->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
   $t->unsignedInteger('versao');$t->string('protocolo',40);$t->foreignId('finalizado_por')->nullable()->constrained('users')->nullOnDelete();
   $t->string('assunto',180);$t->text('resumo');$t->text('solucao')->nullable();$t->text('pendencias')->nullable();$t->json('dados');$t->timestamps();$t->unique(['conversation_id','versao']);
  });
  Schema::create('emails_encerramento',function(Blueprint $t){
   $t->id();$t->foreignId('encerramento_id')->constrained('encerramentos_atendimento')->cascadeOnDelete();$t->string('destinatario_tipo');$t->string('email')->nullable();$t->string('estado')->default('pendente');$t->unsignedInteger('tentativas')->default(0);$t->timestamp('proxima_tentativa_em')->nullable();$t->timestamp('enviado_em')->nullable();$t->timestamps();$t->unique(['encerramento_id','destinatario_tipo']);
  });
 }
 public function down(): void {Schema::dropIfExists('emails_encerramento');Schema::dropIfExists('encerramentos_atendimento');Schema::table('conversations',fn(Blueprint $t)=>$t->dropColumn('protocolo'));Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['perfil','ver_notas_internas']));}
};
