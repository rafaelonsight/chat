<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_assignee', function (Blueprint $table) {
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->primary(['ticket_id', 'user_id']);
            $table->index(['user_id', 'ticket_id']);
        });

        DB::statement('insert into ticket_assignee (ticket_id, user_id, assigned_by, created_at, updated_at) select id, responsible_id, created_by, now(), now() from tickets where responsible_id is not null on conflict do nothing');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_assignee');
    }
};
