<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Messenger e Instagram: dois canais novos, mesmo Token de Pagina.
 *
 * O Instagram Profissional so fala com a API porque esta PENDURADO numa Pagina do
 * Facebook — nao tem credencial propria. Por isso os dois canais reaproveitam o mesmo
 * meta_token (o da Pagina) e so o Instagram ganha o id da conta vinculada. Nao existe
 * "meta_ig_token": seria o mesmo valor duplicado, e duplicado apodrece — no dia da
 * reconexao, um dos dois ficaria com o token velho sem ningum notar.
 *
 * meta_page_id fica solto (sem FK, sem unique): o mesmo padrao de meta_waba_id e
 * meta_phone_number_id, que sao id de recurso da Meta, nao referencia para outra tabela
 * nossa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $tabela) {
            $tabela->string('meta_page_id')->nullable()->after('meta_business_id');
            $tabela->string('meta_page_nome')->nullable()->after('meta_page_id');
            $tabela->string('meta_ig_account_id')->nullable()->after('meta_page_nome');
            $tabela->string('meta_ig_username')->nullable()->after('meta_ig_account_id');
        });

        DB::statement('alter table channels drop constraint if exists channels_tipo_valido');
        DB::statement("alter table channels add constraint channels_tipo_valido
                       check (tipo in ('evolution','meta_cloud','site','messenger','instagram'))");
    }

    public function down(): void
    {
        DB::statement('alter table channels drop constraint if exists channels_tipo_valido');
        DB::statement("alter table channels add constraint channels_tipo_valido
                       check (tipo in ('evolution','meta_cloud','site'))");

        Schema::table('channels', function (Blueprint $tabela) {
            $tabela->dropColumn(['meta_page_id', 'meta_page_nome', 'meta_ig_account_id', 'meta_ig_username']);
        });
    }
};
