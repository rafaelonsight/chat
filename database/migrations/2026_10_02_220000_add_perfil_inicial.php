<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('users',function(Blueprint $t){$t->string('apelido',40)->nullable();$t->string('telefone',30)->nullable();$t->string('cargo',100)->nullable();$t->string('foto_perfil')->nullable();$t->timestamp('perfil_preenchido_em')->nullable();});Schema::table('messages',fn(Blueprint $t)=>$t->string('assinatura_nome',40)->nullable());}
 public function down(): void {Schema::table('messages',fn(Blueprint $t)=>$t->dropColumn('assinatura_nome'));Schema::table('users',fn(Blueprint $t)=>$t->dropColumn(['apelido','telefone','cargo','foto_perfil','perfil_preenchido_em']));}
};
