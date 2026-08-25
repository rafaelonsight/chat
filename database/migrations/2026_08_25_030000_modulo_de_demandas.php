<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_counters', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
        });

        DB::table('ticket_counters')->insert([
            'id' => 1,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('client_accounts')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('direct');
            $table->string('name', 160);
            $table->string('email', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'name']);
            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::create('client_requesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('email', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'client_account_id', 'active']);
        });

        Schema::create('client_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_account_id', 'status']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('protocol', 20)->unique();
            $table->foreignId('client_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('client_requesters')->nullOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description');
            $table->string('category', 40)->default('support');
            $table->string('status', 50)->default('received');
            $table->string('priority', 20)->default('normal');
            $table->string('origin', 20)->default('internal');
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('next_update_at')->nullable();
            $table->timestamp('deadlines_paused_at')->nullable();
            $table->text('deadline_change_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->text('hidden_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'priority']);
            $table->index(['tenant_id', 'client_account_id', 'created_at']);
            $table->index(['tenant_id', 'responsible_id', 'resolution_due_at']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('client_requesters')->nullOnDelete();
            $table->string('visibility', 20)->default('internal');
            $table->text('body');
            $table->jsonb('attachments')->default('[]');
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id', 'created_at']);
        });

        Schema::create('ticket_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('priority', 20)->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id', 'status']);
            $table->index(['tenant_id', 'responsible_id', 'due_at']);
        });

        Schema::create('ticket_task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_task_id')->constrained()->cascadeOnDelete();
            $table->string('text', 240);
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_task_id', 'position']);
        });

        Schema::create('ticket_tag', function (Blueprint $table) {
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['ticket_id', 'tag_id']);
        });

        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 60);
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'ticket_id', 'created_at']);
        });

        DB::statement('alter table tags drop constraint if exists tags_contexto_check');
        DB::statement("alter table tags add constraint tags_contexto_check check (contexto in ('contato','conversa','item','ticket'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('ticket_tag');
        Schema::dropIfExists('ticket_task_checklist_items');
        Schema::dropIfExists('ticket_tasks');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('client_projects');
        Schema::dropIfExists('client_requesters');
        Schema::dropIfExists('client_accounts');
        Schema::dropIfExists('ticket_counters');

        DB::table('tags')->where('contexto', 'ticket')->delete();
        DB::statement('alter table tags drop constraint if exists tags_contexto_check');
        DB::statement("alter table tags add constraint tags_contexto_check check (contexto in ('contato','conversa','item'))");
    }
};
