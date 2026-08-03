<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('nome');

            // Chave de paleta, nao hex. Cor livre produz etiqueta ilegivel — e o
            // contraste do texto deixa de ser garantia nossa.
            $t->string('cor')->default('cinza');

            $t->timestamps();
        });

        // Duas etiquetas com o mesmo nome sao confusao pura na hora de escolher.
        DB::statement('create unique index tags_nome_unico on tags (tenant_id, lower(nome))');

        Schema::create('contact_tag', function (Blueprint $t) {
            $t->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained()->cascadeOnDelete();

            // Quem colocou e por qual caminho. Sem isso ninguem sabe se a etiqueta
            // veio da mao de um atendente, do chatbot ou de um agente de IA — e
            // essa e a primeira pergunta quando uma etiqueta aparece errada.
            $t->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->string('origem')->default('manual');

            $t->timestamp('created_at')->nullable();

            $t->primary(['contact_id', 'tag_id']);
        });

        DB::statement("alter table contact_tag add constraint contact_tag_origem_valida check (origem in ('manual','chatbot','agente','importacao','campanha'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
    }
};
