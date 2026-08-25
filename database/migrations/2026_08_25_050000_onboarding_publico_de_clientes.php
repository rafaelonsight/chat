<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->string('document', 14)->nullable()->after('name');
            $table->string('legal_name', 200)->nullable()->after('document');
            $table->string('trade_name', 200)->nullable()->after('legal_name');
            $table->string('cep', 8)->nullable()->after('phone');
            $table->string('street')->nullable()->after('cep');
            $table->string('number', 30)->nullable()->after('street');
            $table->string('complement')->nullable()->after('number');
            $table->string('district')->nullable()->after('complement');
            $table->string('city')->nullable()->after('district');
            $table->string('state', 2)->nullable()->after('city');
            $table->unique(['tenant_id', 'document']);
        });

        Schema::create('client_onboarding_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_client_account_id')->nullable()->constrained('client_accounts')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->string('status', 20)->default('pending');
            $table->string('company_type', 20)->default('direct');
            $table->jsonb('payload')->default('{}');
            $table->text('review_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_onboarding_requests');
        Schema::table('client_accounts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'document']);
            $table->dropColumn(['document', 'legal_name', 'trade_name', 'cep', 'street', 'number', 'complement', 'district', 'city', 'state']);
        });
    }
};
