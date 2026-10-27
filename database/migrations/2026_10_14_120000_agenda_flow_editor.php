<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('agenda_editor_flows',function(Blueprint $t){$t->id();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();$t->string('name',120);$t->jsonb('draft');$t->unsignedInteger('version')->default(1);$t->timestamps();$t->unique(['tenant_id','id']);});
  Schema::create('agenda_editor_revisions',function(Blueprint $t){$t->id();$t->unsignedBigInteger('tenant_id');$t->unsignedBigInteger('flow_id');$t->unsignedInteger('version');$t->jsonb('graph');$t->timestampTz('created_at');$t->unique(['tenant_id','id']);$t->unique(['flow_id','version']);$t->foreign(['tenant_id','flow_id'])->references(['tenant_id','id'])->on('agenda_editor_flows')->cascadeOnDelete();});
  Schema::table('agenda_flow_settings',function(Blueprint $t){$t->unsignedBigInteger('editor_revision_id')->nullable();$t->foreign(['tenant_id','editor_revision_id'])->references(['tenant_id','id'])->on('agenda_editor_revisions');});
 }
 public function down():void {Schema::table('agenda_flow_settings',function(Blueprint $t){$t->dropForeign(['tenant_id','editor_revision_id']);$t->dropColumn('editor_revision_id');});Schema::dropIfExists('agenda_editor_revisions');Schema::dropIfExists('agenda_editor_flows');}
};
