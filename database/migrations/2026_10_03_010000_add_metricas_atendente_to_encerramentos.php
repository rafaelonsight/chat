<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up(): void {
  Schema::table('encerramentos_atendimento',function(Blueprint $t){
   $t->unsignedBigInteger('responsavel_id')->nullable();$t->string('responsavel_nome',160)->nullable();
   $t->unsignedBigInteger('canal_id')->nullable();$t->unsignedBigInteger('equipe_id')->nullable();
   $t->unsignedBigInteger('pesquisa_message_id')->nullable();$t->timestamp('pesquisa_enviada_em')->nullable();
   $t->unsignedTinyInteger('satisfacao')->nullable();$t->timestamp('satisfacao_em')->nullable();
   $t->unsignedInteger('espera_segundos')->nullable();$t->unsignedInteger('atendimento_segundos')->nullable();
   $t->index(['tenant_id','created_at','responsavel_id'],'encerramentos_relatorio_atendente_idx');
  });
  // Histórico: o único responsável imutável conhecido é quem finalizou.
  DB::statement('UPDATE encerramentos_atendimento f SET responsavel_id=f.finalizado_por, responsavel_nome=u.name, canal_id=c.channel_id, equipe_id=c.team_id FROM conversations c, users u WHERE c.id=f.conversation_id AND u.id=f.finalizado_por AND u.tenant_id=f.tenant_id');
  DB::statement("UPDATE encerramentos_atendimento f SET pesquisa_enviada_em=c.pesquisa_enviada_em,satisfacao=c.satisfacao,satisfacao_em=c.satisfacao_em FROM conversations c WHERE c.id=f.conversation_id AND c.pesquisa_enviada_em>=f.created_at AND f.id=(SELECT MAX(x.id) FROM encerramentos_atendimento x WHERE x.conversation_id=f.conversation_id AND x.created_at<=c.pesquisa_enviada_em)");
 }
 public function down(): void {Schema::table('encerramentos_atendimento',function(Blueprint $t){$t->dropIndex('encerramentos_relatorio_atendente_idx');$t->dropColumn(['responsavel_id','responsavel_nome','canal_id','equipe_id','pesquisa_message_id','pesquisa_enviada_em','satisfacao','satisfacao_em','espera_segundos','atendimento_segundos']);});}
};
