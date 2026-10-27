<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up(): void {DB::statement('drop index if exists tags_nome_unico');DB::statement('create unique index tags_nome_unico on tags (tenant_id, contexto, lower(nome))');Schema::create('etiqueta_movimentos',function(Blueprint $t){$t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('tag_id')->nullable();$t->string('etiqueta_nome',60);$t->string('contexto');$t->string('alvo_tipo');$t->unsignedBigInteger('alvo_id');$t->string('acao');$t->string('origem');$t->unsignedBigInteger('usuario_id')->nullable();$t->timestamp('created_at');$t->index(['tenant_id','alvo_tipo','alvo_id']);});}
 public function down(): void {Schema::dropIfExists('etiqueta_movimentos');/* Preserva nomes iguais em finalidades distintas; restaurar o índice antigo perderia compatibilidade. */}
};
