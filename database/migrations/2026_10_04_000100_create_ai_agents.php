<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::create('ai_agents',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->string('nome',120);$t->string('tipo',20)->default('agente');$t->text('instrucoes');$t->string('modelo',120)->nullable();$t->unsignedSmallInteger('max_turnos')->default(3);$t->boolean('ativo')->default(true);$t->timestamps();$t->index(['tenant_id','tipo','ativo']);});}
 public function down(): void {Schema::dropIfExists('ai_agents');}
};
