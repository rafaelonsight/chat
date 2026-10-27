<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('tenants',fn(Blueprint $t)=>$t->string('email_resumos',160)->nullable());}
 public function down():void {Schema::table('tenants',fn(Blueprint $t)=>$t->dropColumn('email_resumos'));}
};
