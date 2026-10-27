<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('encerramentos_atendimento',fn(Blueprint $t)=>$t->unsignedTinyInteger('avaliacao_cliente')->nullable());}
 public function down(): void {Schema::table('encerramentos_atendimento',fn(Blueprint $t)=>$t->dropColumn('avaliacao_cliente'));}
};
