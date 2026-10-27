<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('meta_tarifas',function(Blueprint $t){
  $t->id();$t->string('mercado',10);$t->string('moeda',3);$t->string('categoria',40);
  $t->decimal('tarifa',16,8)->nullable();$t->jsonb('faixas')->nullable();$t->date('vigente_desde')->nullable();
  $t->timestamp('verificado_em')->nullable();$t->timestamp('tentativa_em')->nullable();$t->string('erro',500)->nullable();
  $t->timestamps();$t->unique(['mercado','moeda','categoria']);
 }); }
 public function down(): void {Schema::dropIfExists('meta_tarifas');}
};
