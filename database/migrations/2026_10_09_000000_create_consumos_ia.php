<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('consumos_ia',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();$t->unsignedBigInteger('user_id')->nullable();$t->string('recurso',80);$t->string('provedor',40);$t->string('modelo',180);$t->unsignedBigInteger('entrada')->nullable();$t->unsignedBigInteger('saida')->nullable();$t->unsignedBigInteger('total')->nullable();$t->timestamp('created_at');$t->index(['tenant_id','created_at']);});}
 public function down():void {Schema::dropIfExists('consumos_ia');}
};
