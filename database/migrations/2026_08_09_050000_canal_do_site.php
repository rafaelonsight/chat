<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * O chat do site: um canal como qualquer outro.
 *
 * A DECISAO QUE SUSTENTA TUDO ISTO e que o visitante do site nao e um tipo novo de coisa: e um
 * CONTATO, numa CONVERSA, num CANAL. Uma caixa de entrada separada so para o site significaria
 * dois lugares para olhar, duas filas, dois relatorios — e o atendente esquecendo de um deles
 * na primeira semana movimentada.
 *
 * O QUE MUDA E QUE ELE NAO TEM TELEFONE. A identidade dele e uma chave aleatoria guardada no
 * proprio navegador, e ela vira o jid do contato. Quem volta ao site amanha com o mesmo
 * navegador cai na mesma conversa; quem troca de aparelho vira outra pessoa, e isso e honesto:
 * nao temos como saber que e a mesma.
 *
 * A CHAVE DO SITE E PUBLICA de proposito — ela vai dentro do HTML de quem instalar o widget, e
 * qualquer um consegue ler. Ela nao autoriza nada alem de abrir conversa e falar naquele canal,
 * que e exatamente o que um formulario de contato tambem permite. O que a protege de virar
 * porta de spam e o teto por IP, nao o segredo dela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Publica: viaja no HTML de quem instala. Nao e credencial, e endereco.
            $table->string('site_key', 40)->nullable()->unique()->after('webhook_secret');

            // Onde o widget pode aparecer. Vazio = qualquer lugar.
            $table->string('site_dominio', 160)->nullable()->after('site_key');

            // A primeira coisa que o visitante le antes de escrever.
            $table->string('site_saudacao', 200)->nullable()->after('site_dominio');
        });

        DB::statement('alter table channels drop constraint if exists channels_tipo_valido');
        DB::statement("alter table channels add constraint channels_tipo_valido
                       check (tipo in ('evolution','meta_cloud','site'))");
    }

    public function down(): void
    {
        DB::statement('alter table channels drop constraint if exists channels_tipo_valido');
        DB::statement("alter table channels add constraint channels_tipo_valido
                       check (tipo in ('evolution','meta_cloud'))");

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['site_key', 'site_dominio', 'site_saudacao']);
        });
    }
};
