<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('trial_monitorado')->default(false)->index();
        });

        Schema::create('trial_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tipo', 80);
            $table->string('gravidade', 20);
            $table->string('ip_hash', 64)->nullable();
            $table->string('rota', 500)->nullable();
            $table->string('metodo', 10)->nullable();
            $table->unsignedSmallInteger('status_http')->nullable();
            $table->jsonb('detalhes')->default('{}');
            $table->timestamp('ocorreu_em');
            $table->timestamps();
            $table->index(['tenant_id', 'ocorreu_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_security_events');
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('trial_monitorado');
        });
    }
};
