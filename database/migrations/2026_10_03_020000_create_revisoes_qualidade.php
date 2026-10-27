<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::create('revisoes_qualidade',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('encerramento_id')->constrained('encerramentos_atendimento')->cascadeOnDelete();$t->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();$t->string('autor_nome');$t->string('estado',30);$t->text('comentario');$t->timestamps();$t->index(['tenant_id','encerramento_id','id']);});}
 public function down(): void {Schema::dropIfExists('revisoes_qualidade');}
};
