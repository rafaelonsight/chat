<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 * webhook_events e diario de bordo, nao arquivo. Serve para reprocessar e para investigar o que
 * chegou; depois disso so ocupa espaco. Em producao chegou a 95% do banco.
 */
class LimparWebhooks extends Command
{
    protected $signature = 'virtus:limpar-webhooks';

    protected $description = 'Apaga avisos de webhook antigos e tira o base64 dos que ficam';

    public const DIAS_PROCESSADO = 7;

    // Com erro fica mais: e o que alguem vai querer olhar para entender a falha.
    public const DIAS_COM_ERRO = 30;

    private const LOTE = 5000;

    public function handle(): int
    {
        $ok = $this->apagar(fn ($q) => $q->whereNotNull('processado_em')->whereNull('erro')
            ->where('recebido_em', '<', now()->subDays(self::DIAS_PROCESSADO)));
        $erro = $this->apagar(fn ($q) => $q->whereNotNull('erro')
            ->where('recebido_em', '<', now()->subDays(self::DIAS_COM_ERRO)));

        $enxugados = DB::update("UPDATE webhook_events SET payload = payload #- '{data,message,base64}' WHERE payload->'data'->'message'->'base64' IS NOT NULL");

        $this->info("Apagados: {$ok} processados, {$erro} com erro. Sem base64: {$enxugados}.");

        return self::SUCCESS;
    }

    // Em lotes: um DELETE de milhoes de linhas prende a tabela que o webhook esta gravando agora.
    private function apagar(callable $filtro): int
    {
        $total = 0;
        do {
            $ids = $filtro(DB::table('webhook_events')->select('id'))->orderBy('id')->limit(self::LOTE)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table('webhook_events')->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::LOTE);

        return $total;
    }
}
