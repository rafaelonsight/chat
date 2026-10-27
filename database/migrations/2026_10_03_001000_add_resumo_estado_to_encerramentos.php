<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('encerramentos_atendimento',function(Blueprint $t){$t->string('resumo_estado',20)->default('pronto');$t->unsignedBigInteger('ultima_mensagem_id')->nullable();});}
 public function down(): void {Schema::table('encerramentos_atendimento',fn(Blueprint $t)=>$t->dropColumn(['resumo_estado','ultima_mensagem_id']));}
};
