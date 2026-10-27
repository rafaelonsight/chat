<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('knowledge_documents',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained('tenants');$t->string('titulo',160);$t->string('idioma',8)->default('en_GB');$t->text('conteudo');$t->boolean('ativo')->default(true);$t->timestamps();$t->index(['tenant_id','ativo']);});
  Schema::create('knowledge_chunks',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained('tenants');$t->foreignId('knowledge_document_id')->constrained()->cascadeOnDelete();$t->unsignedInteger('posicao');$t->text('conteudo');$t->text('busca');$t->index(['tenant_id','knowledge_document_id']);});
  Schema::table('ai_agents',fn(Blueprint $t)=>$t->json('fontes_conhecimento')->nullable());
 }
 public function down():void {Schema::table('ai_agents',fn(Blueprint $t)=>$t->dropColumn('fontes_conhecimento'));Schema::dropIfExists('knowledge_chunks');Schema::dropIfExists('knowledge_documents');}
};
