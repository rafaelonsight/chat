<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {public function up():void{Schema::table('consumos_ia',function(Blueprint $t){$t->unsignedBigInteger('message_id')->nullable()->unique();$t->decimal('segundos_audio',12,3)->nullable();});}public function down():void{Schema::table('consumos_ia',fn(Blueprint $t)=>$t->dropColumn(['message_id','segundos_audio']));}};
