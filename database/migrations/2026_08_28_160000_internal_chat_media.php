<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('direct_messages',function(Blueprint $t){$t->string('file_path')->nullable();$t->string('file_name')->nullable();$t->string('file_mime')->nullable();$t->unsignedBigInteger('file_size')->nullable();});}
    public function down():void{Schema::table('direct_messages',fn(Blueprint $t)=>$t->dropColumn(['file_path','file_name','file_mime','file_size']));}
};
