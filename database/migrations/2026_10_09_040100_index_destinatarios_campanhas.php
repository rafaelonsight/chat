<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {Schema::table('campaign_recipients',fn(Blueprint $t)=>$t->index('contact_id'));}
 public function down(): void {Schema::table('campaign_recipients',fn(Blueprint $t)=>$t->dropIndex(['contact_id']));}
};
