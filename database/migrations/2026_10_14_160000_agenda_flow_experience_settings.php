<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('agenda_flow_settings',function(Blueprint $t){$t->string('professional_term',40)->default('profissional');$t->string('assignment_strategy',30)->default('alphabetical');$t->jsonb('support_hours')->default('[]');$t->text('handoff_after_hours')->nullable();});}
 public function down():void {Schema::table('agenda_flow_settings',fn(Blueprint $t)=>$t->dropColumn(['professional_term','assignment_strategy','support_hours','handoff_after_hours']));}
};
