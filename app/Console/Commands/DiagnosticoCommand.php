<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Diagnostico;
use App\Services\EvolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DiagnosticoCommand extends Command
{
    protected $signature = 'onchat:diagnostico {--alertar : envia alerta dos problemas encontrados}';

    protected $description = 'Verifica se algo esta quebrado e, com --alertar, avisa';

    public function handle(Diagnostico $diagnostico): int
    {
        $problemas = $diagnostico->verificar();

        if ($problemas === []) {
            $this->info('Tudo certo.');

            return self::SUCCESS;
        }

        foreach ($problemas as $p) {
            $linha = strtoupper($p['nivel']).': '.$p['mensagem'];
            $p['nivel'] === Diagnostico::CRITICO ? $this->error($linha) : $this->warn($linha);
        }

        if ($this->option('alertar')) {
            $this->alertar($problemas);
        }

        // Sai diferente de zero so no critico: aviso nao deve fazer o agendador
        // parecer quebrado todo dia.
        return collect($problemas)->contains(fn ($p) => $p['nivel'] === Diagnostico::CRITICO)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function alertar(array $problemas): void
    {
        $minutos = (int) config('onchat.alerta_silencio_minutos');

        foreach ($problemas as $p) {
            $chave = 'alerta:'.$p['chave'];

            // Silencio por chave: uma queda de dez minutos nao pode virar dez
            // avisos identicos, senao o alerta passa a ser ignorado.
            if (Cache::has($chave)) {
                continue;
            }

            Cache::put($chave, true, now()->addMinutes($minutos));

            $p['nivel'] === Diagnostico::CRITICO
                ? Log::error('[OnChat] '.$p['mensagem'], ['chave' => $p['chave']])
                : Log::warning('[OnChat] '.$p['mensagem'], ['chave' => $p['chave']]);

            $this->porWhatsapp($p);
        }
    }

    private function porWhatsapp(array $problema): void
    {
        $destino = trim((string) config('onchat.alerta_whatsapp'));

        if ($destino === '') {
            return;
        }

        // Se a Evolution caiu, o aviso pela Evolution nao sai. E por isso que
        // /saude existe: monitor de fora nao depende de nada aqui estar de pe.
        $canal = Channel::withoutGlobalScope('tenant')->where('status', 'open')->first();

        if (! $canal) {
            return;
        }

        try {
            app(EvolutionService::class)->sendText(
                $canal->instance_name,
                $destino,
                '[OnChat] '.strtoupper($problema['nivel']).': '.$problema['mensagem'],
            );
        } catch (\Throwable $e) {
            Log::warning('Nao consegui alertar por WhatsApp', ['erro' => $e->getMessage()]);
        }
    }
}
