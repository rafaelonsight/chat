<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A proposta ganha estrutura: blocos com TIPO, preco-ancora e condicao de pagamento.
 *
 * POR QUE OS BLOCOS PRECISAM DE TIPO. Hoje cada bloco e titulo mais texto livre. Isso cabe tudo
 * e nao guia nada: quem escreve com pressa escreve um paragrafo so, e a proposta perde o que
 * mais vende — mostrar que entendeu o problema ANTES de falar preco. Com tipo, a estrutura passa
 * a ser cobrada pelo formulario: diagnostico pede as dores numeradas, plano de acao pede a
 * solucao de cada uma, cronograma pede etapa por etapa.
 *
 * O PRECO-ANCORA nao e enfeite de vendedor: sem o valor cheio ao lado, o desconto nao existe
 * para quem le. "R$ 3.299" e um preco; "R$ 3.500, e R$ 3.299 para pagamento em dia" e uma escolha.
 *
 * VENCIMENTO E PRIMEIRO PAGAMENTO fecham a duvida que hoje volta por WhatsApp no dia seguinte ao
 * aceite: "e quando eu pago?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $t) {
            $t->decimal('valor_cheio_unico', 12, 2)->nullable()->after('total_unico');
            $t->decimal('valor_cheio_recorrente', 12, 2)->nullable()->after('total_recorrente');
            $t->smallInteger('vencimento_dia')->nullable()->after('desconto');
            $t->date('primeiro_pagamento')->nullable()->after('vencimento_dia');
            $t->json('selos')->nullable()->after('primeiro_pagamento');
        });

        /*
         * O TETO DO DIA DE VENCIMENTO E 28, e nao 31.
         *
         * Dia 29, 30 e 31 nao existem em todo mes. Vencimento no dia 31 vira "ultimo dia" em onze
         * meses sem ninguem ter combinado isso, e em fevereiro nao vira nada. Vinte e oito e o
         * unico teto que significa a mesma coisa nos doze meses.
         */
        DB::statement(
            'ALTER TABLE proposals ADD CONSTRAINT proposals_vencimento_dia_check
             CHECK (vencimento_dia IS NULL OR (vencimento_dia BETWEEN 1 AND 28))'
        );

        $this->darTipoAosBlocos('proposals');
        $this->darTipoAosBlocos('proposal_templates');
    }

    /**
     * Os blocos que ja existem viram blocos do tipo 'texto'.
     *
     * O FORMATO NOVO E O DO CONSTRUTOR do Filament: cada bloco e {type, data}. Sem esta
     * conversao, a proposta que ja esta escrita abriria vazia no formulario — e o texto
     * continuaria no banco, invisivel, ate alguem salvar por cima e perde-lo de vez.
     */
    private function darTipoAosBlocos(string $tabela): void
    {
        foreach (DB::table($tabela)->whereNotNull('blocos')->get(['id', 'blocos']) as $linha) {
            $blocos = json_decode($linha->blocos, true);

            if (! is_array($blocos) || $blocos === []) {
                continue;
            }

            $novos = [];

            foreach ($blocos as $bloco) {
                if (! is_array($bloco)) {
                    continue;
                }

                // Ja convertido: nao mexe. A migracao pode rodar depois de um deploy parcial.
                $novos[] = array_key_exists('type', $bloco)
                    ? $bloco
                    : ['type' => 'texto', 'data' => [
                        'titulo' => $bloco['titulo'] ?? null,
                        'corpo' => $bloco['corpo'] ?? null,
                    ]];
            }

            DB::table($tabela)->where('id', $linha->id)->update(['blocos' => json_encode($novos)]);
        }
    }

    /**
     * A volta desfaz o embrulho do que era texto.
     *
     * Bloco de diagnostico, cronograma ou assinante NAO tem para onde voltar: o formato antigo
     * nao sabe representa-los. Eles somem — e isso esta escrito aqui para quem for rodar o down
     * saber o que perde ANTES de rodar.
     */
    public function down(): void
    {
        foreach (['proposals', 'proposal_templates'] as $tabela) {
            foreach (DB::table($tabela)->whereNotNull('blocos')->get(['id', 'blocos']) as $linha) {
                $blocos = json_decode($linha->blocos, true);

                if (! is_array($blocos)) {
                    continue;
                }

                $antigos = [];

                foreach ($blocos as $bloco) {
                    if (($bloco['type'] ?? null) === 'texto') {
                        $antigos[] = [
                            'titulo' => $bloco['data']['titulo'] ?? null,
                            'corpo' => $bloco['data']['corpo'] ?? null,
                        ];
                    }
                }

                DB::table($tabela)->where('id', $linha->id)->update(['blocos' => json_encode($antigos)]);
            }
        }

        DB::statement('ALTER TABLE proposals DROP CONSTRAINT IF EXISTS proposals_vencimento_dia_check');

        Schema::table('proposals', function (Blueprint $t) {
            $t->dropColumn([
                'valor_cheio_unico', 'valor_cheio_recorrente',
                'vencimento_dia', 'primeiro_pagamento', 'selos',
            ]);
        });
    }
};
