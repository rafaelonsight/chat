<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void {Schema::table('agenda_finance_options',function(Blueprint $t){$t->string('account_type',12)->nullable();$t->string('bank_name',80)->nullable();$t->string('agency',24)->nullable();$t->string('account_number',32)->nullable();$t->bigInteger('opening_cents')->default(0);$t->date('opening_on')->nullable();$t->boolean('active')->default(true);$t->unsignedInteger('version')->default(1);});DB::statement("ALTER TABLE agenda_finance_options ADD CONSTRAINT agenda_bank_kind CHECK (account_type IS NULL OR (type='bank' AND account_type IN ('checking','savings','cash')))");}
 public function down():void {DB::statement('ALTER TABLE agenda_finance_options DROP CONSTRAINT agenda_bank_kind');Schema::table('agenda_finance_options',fn(Blueprint $t)=>$t->dropColumn(['account_type','bank_name','agency','account_number','opening_cents','opening_on','active','version']));}
};
