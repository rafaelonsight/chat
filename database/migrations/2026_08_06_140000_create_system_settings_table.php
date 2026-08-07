<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Um guarda de chave e valor do SISTEMA, sem tenant.
 *
 * Nasce para uma pergunta so: "algum e-mail ja saiu daqui de verdade?". Poderia ficar no
 * cache, mas o cache e o Redis e um flush faria o diagnostico dizer "nunca enviou" sobre um
 * sistema que envia ha meses. Alarme falso e o jeito mais rapido de ensinar alguem a ignorar
 * alarme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('chave')->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
