<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agenda_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->decimal('preco', 10, 2);
            $table->unsignedInteger('duracao_minutos');
            $table->unsignedInteger('intervalo_minutos')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id','id']);
            $table->index(['tenant_id','ativo','nome']);
        });
        Schema::create('agenda_professionals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 30)->nullable();
            $table->string('cor', 7)->default('#6338F6');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id','id']);
            $table->index(['tenant_id','ativo','nome']);
        });
        Schema::create('agenda_professional_service', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('professional_id');
            $table->unsignedBigInteger('service_id');
            $table->timestamps();
            $table->primary(['professional_id','service_id']);
            $table->foreign(['tenant_id','professional_id'])->references(['tenant_id','id'])->on('agenda_professionals')->restrictOnDelete();
            $table->foreign(['tenant_id','service_id'])->references(['tenant_id','id'])->on('agenda_services')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE agenda_services ADD CONSTRAINT agenda_services_values CHECK (preco >= 0 AND duracao_minutos BETWEEN 1 AND 1440 AND intervalo_minutos BETWEEN 0 AND 1440)');
        DB::statement("ALTER TABLE agenda_professionals ADD CONSTRAINT agenda_professionals_color CHECK (cor ~ '^#[0-9A-Fa-f]{6}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('agenda_professional_service');
        Schema::dropIfExists('agenda_professionals');
        Schema::dropIfExists('agenda_services');
    }
};
