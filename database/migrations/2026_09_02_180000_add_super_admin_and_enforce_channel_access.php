<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('super_admin')->default(false)->index();
        });

        // Rafael e o proprietario informado para a instalacao atual. Ninguem mais e promovido
        // implicitamente: admin continua sendo papel funcional, nao acesso global.
        DB::table('users')
            ->where('tenant_id', 1452)
            ->where('email', 'rafael@onsight.com.br')
            ->update(['super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('super_admin');
        });
    }
};
