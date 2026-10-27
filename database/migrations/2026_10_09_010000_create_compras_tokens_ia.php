<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('compras_tokens_ia',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('tokens');$t->unsignedInteger('valor_centavos');$t->string('status')->default('solicitada');$t->timestamps();$t->index(['tenant_id','status']);});}
 public function down():void {Schema::dropIfExists('compras_tokens_ia');}
};
