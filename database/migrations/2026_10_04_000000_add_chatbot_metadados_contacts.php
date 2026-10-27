<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('contacts',fn(Blueprint $t)=>$t->jsonb('chatbot_metadados')->nullable()); } public function down(): void { Schema::table('contacts',fn(Blueprint $t)=>$t->dropColumn('chatbot_metadados')); } };
