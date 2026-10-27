<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('revisoes_qualidade',function(Blueprint $t){$t->foreignId('responsavel_id')->nullable()->constrained('users')->nullOnDelete();$t->string('responsavel_nome')->nullable();$t->timestamp('prazo_em')->nullable();});
  Schema::create('avisos_qualidade',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->foreignId('encerramento_id')->constrained('encerramentos_atendimento')->cascadeOnDelete();$t->foreignId('user_id')->constrained('users')->cascadeOnDelete();$t->string('tipo',30);$t->timestamps();$t->unique(['encerramento_id','user_id','tipo']);});
 }
 public function down(): void {Schema::dropIfExists('avisos_qualidade');Schema::table('revisoes_qualidade',function(Blueprint $t){$t->dropConstrainedForeignId('responsavel_id');$t->dropColumn(['responsavel_nome','prazo_em']);});}
};
