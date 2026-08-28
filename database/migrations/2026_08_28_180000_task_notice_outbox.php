<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('board_notice_outbox',function(Blueprint $t){
  $t->uuid('id')->primary();$t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
  $t->foreignId('board_item_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->constrained()->cascadeOnDelete();
  $t->string('title');$t->text('url');$t->unsignedInteger('attempts')->default(0);$t->timestamp('available_at')->index();
  $t->timestamp('delivered_at')->nullable();$t->timestamp('created_at');
 });}
 public function down():void {Schema::dropIfExists('board_notice_outbox');}
};
