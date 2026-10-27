<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequencias_protocolo', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('ano');
            $t->unsignedBigInteger('ultimo')->default(0);
            $t->primary(['tenant_id', 'ano']);
        });
        Schema::table('conversations', function (Blueprint $t) {
            $t->dropUnique('conversations_protocolo_unique');
            $t->unique(['tenant_id', 'protocolo']);
        });
    }
    public function down(): void
    {
        // Após emissão de números iguais em contas distintas, não se pode restaurar
        // a unicidade global sem reescrever protocolos já informados ao cliente.
        throw new RuntimeException('Migração de protocolos emitidos não pode ser revertida automaticamente.');
    }
};
