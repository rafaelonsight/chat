<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table tags drop constraint if exists tags_contexto_check');
        DB::statement("alter table tags add constraint tags_contexto_check check (contexto in ('contato','conversa','item'))");
    }

    public function down(): void
    {
        DB::table('tags')->where('contexto', 'item')->delete();
        DB::statement('alter table tags drop constraint if exists tags_contexto_check');
        DB::statement("alter table tags add constraint tags_contexto_check check (contexto in ('contato','conversa'))");
    }
};
