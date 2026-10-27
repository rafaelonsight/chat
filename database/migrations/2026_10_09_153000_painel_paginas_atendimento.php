<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void {Schema::table('attendance_actions',fn(Blueprint $t)=>$t->string('modo_pagina',20)->default('nova_aba'));}public function down():void {Schema::table('attendance_actions',fn(Blueprint $t)=>$t->dropColumn('modo_pagina'));}};
