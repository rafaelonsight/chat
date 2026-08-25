<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->boolean('active')->default(true);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('client_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('email', 160);
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['client_account_id', 'email']);
        });

        DB::table('client_accounts')->orderBy('id')->each(function ($client) {
            $token = Str::random(64);
            DB::table('client_portals')->insert([
                'tenant_id' => $client->tenant_id, 'client_account_id' => $client->id,
                'token_hash' => hash('sha256', $token), 'token_encrypted' => Crypt::encryptString($token),
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        Schema::create('portal_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_admin_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['client_portal_id', 'created_at']);
        });

        Schema::create('portal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_admin_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('client_requesters')->nullOnDelete();
            $table->string('category', 40);
            $table->string('status', 30)->default('requested');
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->jsonb('participant_user_ids')->default('[]');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'starts_at', 'status']);
        });

        Schema::create('whatsapp_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->string('status', 30)->default('queued');
            $table->jsonb('payload')->default('{}');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'ticket_id', 'created_at']);
        });

        DB::statement("alter table tickets drop constraint if exists tickets_origin_check");
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_deliveries');
        Schema::dropIfExists('ticket_meetings');
        Schema::dropIfExists('portal_sessions');
        Schema::dropIfExists('portal_otps');
        Schema::dropIfExists('client_admins');
        Schema::dropIfExists('client_portals');
    }
};
