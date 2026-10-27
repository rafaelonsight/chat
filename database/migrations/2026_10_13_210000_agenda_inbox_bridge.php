<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('channels',fn(Blueprint $t)=>$t->unique(['tenant_id','id'],'agenda_channels_identity'));
  Schema::table('agenda_channel_connections',function(Blueprint $t){$t->foreignId('channel_id')->nullable()->unique();$t->foreign(['tenant_id','channel_id'])->references(['tenant_id','id'])->on('channels')->restrictOnDelete();});
 }
 public function down():void {Schema::table('agenda_channel_connections',function(Blueprint $t){$t->dropForeign(['tenant_id','channel_id']);$t->dropColumn('channel_id');});Schema::table('channels',fn(Blueprint $t)=>$t->dropUnique('agenda_channels_identity'));}
};
