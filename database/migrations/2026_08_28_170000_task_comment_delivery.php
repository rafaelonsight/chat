<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::table('board_item_updates',function(Blueprint $t){
   $t->uuid('submission_id')->nullable()->unique();
   $t->unsignedInteger('revision')->default(1);
   $t->json('notified_mentions')->nullable();
   $t->timestamp('deleted_at')->nullable();
  });
  Schema::create('board_comment_reactions',function(Blueprint $t){
   $t->id();$t->foreignId('board_item_update_id')->constrained()->cascadeOnDelete();
   $t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->string('emoji',16);
   $t->unique(['board_item_update_id','user_id','emoji'],'board_reaction_unique');
  });
 }
 public function down():void {
  Schema::dropIfExists('board_comment_reactions');
  Schema::table('board_item_updates',fn(Blueprint $t)=>$t->dropColumn(['submission_id','revision','notified_mentions','deleted_at']));
 }
};
