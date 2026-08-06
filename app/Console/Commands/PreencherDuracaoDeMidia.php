<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\MediaService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Preenche a duracao de audio e video que ja estao no disco sem ela.
 *
 * Existe porque a correcao chegou depois das mensagens: quem recebeu audio pelo canal
 * oficial antes disto tem o arquivo guardado e a bolha sem tempo. O arquivo esta aqui, o
 * ffprobe sabe responder, e reprocessar nao custa nada — nao ha motivo para deixar o
 * historico pior que as mensagens novas.
 *
 * Nao baixa nada e nao chama provedor nenhum: le so o que ja esta no disco.
 */
class PreencherDuracaoDeMidia extends Command
{
    protected $signature = 'onchat:duracao-de-midia {--limite=500}';

    protected $description = 'Le a duracao de audio e video ja guardados que estao sem ela';

    public function handle(MediaService $media): int
    {
        if (! $media->temFfprobe()) {
            $this->error('ffprobe nao esta instalado: sem ele nao da para ler duracao.');

            return self::FAILURE;
        }

        $pendentes = Message::withoutGlobalScope('tenant')
            ->whereIn('tipo', ['audio', 'video'])
            ->whereNotNull('media_path')
            ->whereNull('media_duracao')
            ->limit((int) $this->option('limite'))
            ->get();

        if ($pendentes->isEmpty()) {
            $this->info('Nada para preencher.');

            return self::SUCCESS;
        }

        $preenchidas = 0;
        $semResposta = 0;

        foreach ($pendentes as $mensagem) {
            $caminho = Storage::disk('local')->path((string) $mensagem->media_path);
            $duracao = $media->duracaoSegundos($caminho);

            if ($duracao === null) {
                $semResposta++;

                continue;
            }

            // runAs porque comando de console nao tem usuario logado: sem contexto de
            // tenant o escopo global nao deixa gravar.
            TenantContext::runAs((int) $mensagem->tenant_id, fn () => $mensagem->update(['media_duracao' => $duracao]));

            $preenchidas++;
        }

        $this->info("Preenchidas: {$preenchidas}. Sem duracao legivel: {$semResposta}.");

        return self::SUCCESS;
    }
}
