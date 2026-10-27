<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void {
  Schema::table('agenda_products',function(Blueprint $t){$t->string('photo_path')->nullable();});
  DB::statement('ALTER TABLE agenda_finance_options DROP CONSTRAINT agenda_option_type');
  DB::statement("ALTER TABLE agenda_finance_options ADD CONSTRAINT agenda_option_type CHECK (type IN ('category','method','bank'))");
  Schema::table('agenda_finance_payments',function(Blueprint $t){$t->unsignedBigInteger('bank_id')->nullable();$t->foreign(['tenant_id','bank_id'])->references(['tenant_id','id'])->on('agenda_finance_options')->restrictOnDelete();$t->index(['tenant_id','bank_id','paid_on']);});
 }
 public function down():void {Schema::table('agenda_finance_payments',function(Blueprint $t){$t->dropForeign(['tenant_id','bank_id']);$t->dropIndex(['tenant_id','bank_id','paid_on']);$t->dropColumn('bank_id');});Schema::table('agenda_products',fn(Blueprint $t)=>$t->dropColumn('photo_path'));}
};
