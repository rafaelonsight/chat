<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('tenants',function(Blueprint $t){$t->string('idioma_padrao',10)->default('pt_BR');$t->string('idioma_comunicacoes',10)->default('pt_BR');});
  Schema::table('users',fn(Blueprint $t)=>$t->string('idioma_interface',10)->nullable());
 }
 public function down(): void {Schema::table('users',fn(Blueprint $t)=>$t->dropColumn('idioma_interface'));Schema::table('tenants',fn(Blueprint $t)=>$t->dropColumn(['idioma_padrao','idioma_comunicacoes']));}
};
