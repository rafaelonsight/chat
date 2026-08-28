<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        Schema::table('board_items',function(Blueprint $t){
            $t->timestamp('inicio_em')->nullable();
            $t->boolean('modelo')->default(false);
            $t->string('recorrencia',12)->nullable();
            $t->foreignId('occurrence_of')->nullable()->constrained('board_items')->nullOnDelete();
            $t->unique('occurrence_of');
        });
        Schema::table('funnels',fn(Blueprint $t)=>$t->string('espaco',60)->nullable());
        Schema::table('board_fields',fn(Blueprint $t)=>$t->timestamp('arquivado_em')->nullable());
        Schema::create('board_favorites',function(Blueprint $t){
            $t->id();$t->foreignId('funnel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();$t->unique(['funnel_id','user_id']);
        });
    }
    public function down():void
    {
        Schema::dropIfExists('board_favorites');
        Schema::table('board_items',function(Blueprint $t){$t->dropUnique(['occurrence_of']);$t->dropConstrainedForeignId('occurrence_of');$t->dropColumn(['inicio_em','modelo','recorrencia']);});
        Schema::table('funnels',fn(Blueprint $t)=>$t->dropColumn('espaco'));
        Schema::table('board_fields',fn(Blueprint $t)=>$t->dropColumn('arquivado_em'));
    }
};
