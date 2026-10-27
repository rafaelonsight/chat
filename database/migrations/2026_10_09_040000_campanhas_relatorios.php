<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('campaigns', function(Blueprint $t){$t->jsonb('configuracao')->nullable();$t->string('motivo_pausa')->nullable();});
  Schema::table('campaign_recipients', fn(Blueprint $t)=>$t->string('classificacao',30)->nullable());
  Schema::create('campaign_events',function(Blueprint $t){$t->id();$t->foreignId('campaign_id')->constrained()->cascadeOnDelete();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('acao');$t->text('detalhe')->nullable();$t->timestamp('created_at')->useCurrent();});
  Schema::table('campaign_recipients', fn(Blueprint $t)=>$t->index('message_id'));
 }
 public function down(): void {
  Schema::dropIfExists('campaign_events');
  Schema::table('campaign_recipients',function(Blueprint $t){$t->dropIndex(['message_id']);$t->dropColumn('classificacao');});
  Schema::table('campaigns',fn(Blueprint $t)=>$t->dropColumn(['configuracao','motivo_pausa']));
 }
};
