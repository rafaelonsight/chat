<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::statement('ALTER TABLE agenda_channel_connections DROP CONSTRAINT agenda_channel_status');DB::statement("ALTER TABLE agenda_channel_connections ADD CONSTRAINT agenda_channel_status CHECK(status IN ('configurado','validado','desconectado','falha','conectando','conectado'))");}
 public function down():void {DB::table('agenda_channel_connections')->whereIn('status',['conectando','conectado'])->update(['status'=>'configurado']);DB::statement('ALTER TABLE agenda_channel_connections DROP CONSTRAINT agenda_channel_status');DB::statement("ALTER TABLE agenda_channel_connections ADD CONSTRAINT agenda_channel_status CHECK(status IN ('configurado','validado','desconectado','falha'))");}
};
