<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('messages', function (Blueprint $t) {
            $t->string('agenda_delivery_state', 24)->nullable();
            $t->unsignedSmallInteger('agenda_delivery_attempts')->default(0);
            $t->timestampTz('agenda_delivery_started_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn(['agenda_delivery_state','agenda_delivery_attempts','agenda_delivery_started_at']));
    }
};
