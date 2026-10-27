<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('codigos_de_acesso', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('painel', 20);
            $table->string('sessao_hash', 64);
            $table->string('codigo_hash', 64);
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->timestamp('expira_em')->index();
            $table->timestamp('usado_em')->nullable();
            $table->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('codigos_de_acesso'); }
};
