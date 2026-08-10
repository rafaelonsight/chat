<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\SystemSetting;
use App\Services\EvolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Confere — e corrige — para onde a Evolution avisa.
 *
 * POR QUE ISSO EXISTE: o endereco do aviso nao mora aqui. Ele mora dentro da Evolution,
 * gravado uma unica vez quando o canal nasceu. O painel pode mudar de dominio, e ela continua
 * avisando no endereco antigo para sempre, sem reclamar de nada.
 *
 * E foi o que aconteceu: apos a mudanca para virtus.chat, o endereco velho passou a responder
 * um redirecionamento. A Evolution nao segue redirecionamento — ela POSTa, recebe 302 e
 * considera entregue. Duas dias de mensagens de cliente cairam nesse buraco, e do lado de
 * dentro nada parecia errado: nenhum erro, nenhuma fila, nenhuma falha. So silencio.
 *
 * E POR QUE ELE CORRIGE, EM VEZ DE SO AVISAR: aqui nao existe ambiguidade sobre o estado
 * desejado. O aviso TEM de chegar neste servidor; qualquer outro endereco e defeito, nunca
 * escolha. Alertar e esperar intervencao humana custaria mais horas de surdez pelo caminho.
 *
 * O SELO no fim e o que torna isso vigiavel: ele so e gravado quando TODOS os canais foram
 * conferidos sem erro. O diagnostico olha a idade dele — e por isso um alerta acende tanto
 * quando o apontamento esta errado e nao pode ser corrigido, quanto quando este comando
 * simplesmente parou de rodar. Vigia que depende de si mesmo para se declarar vivo nao vigia.
 */
class ConferirWebhooks extends Command
{
    /** O nome do selo que prova que a conferencia aconteceu. */
    public const SELO = 'webhooks.conferidos_em';

    protected $signature = 'onchat:conferir-webhooks
                            {--so-conferir : Apenas relata a diferenca, sem corrigir}';

    protected $description = 'Confere se a Evolution avisa neste servidor, e reaponta o que estiver fora';

    public function handle(EvolutionService $evolution): int
    {
        // withoutGlobalScope porque em console nao ha usuario logado: com o escopo de tenant
        // ativo a busca nao acharia canal nenhum e o comando diria "tudo certo" sobre o vazio.
        $canais = Channel::withoutGlobalScope('tenant')
            ->where('tipo', Channel::EVOLUTION)
            ->whereNotNull('instance_name')
            ->get();

        if ($canais->isEmpty()) {
            $this->info('Nenhum canal Evolution para conferir.');
            SystemSetting::gravar(self::SELO, now()->toIso8601String());

            return self::SUCCESS;
        }

        $corrigidos = 0;
        $comErro = 0;

        foreach ($canais as $canal) {
            $esperado = $canal->webhookUrl();

            try {
                $atual = (string) (data_get($evolution->acharWebhook($canal->instance_name), 'url') ?? '');

                if ($atual === $esperado) {
                    $this->line("  ok   {$canal->instance_name}");

                    continue;
                }

                if ($this->option('so-conferir')) {
                    $this->warn("  fora {$canal->instance_name}: avisa em ".($atual ?: 'lugar nenhum'));
                    $comErro++;

                    continue;
                }

                $evolution->apontarWebhook($canal->instance_name, $esperado);
                $corrigidos++;

                // Nivel de aviso e nao de informacao: se isto aconteceu, mensagem de cliente
                // esteve caindo fora ate agora, e o registro precisa aparecer para quem for
                // entender depois por que houve buraco no historico.
                Log::warning('Webhook da Evolution apontava para fora e foi corrigido', [
                    'canal'    => $canal->id,
                    'instance' => $canal->instance_name,
                    'antes'    => $atual ?: null,
                    'agora'    => $esperado,
                ]);

                $this->warn("  ->   {$canal->instance_name}: reapontado (antes: ".($atual ?: 'nada').')');
            } catch (\Throwable $e) {
                $comErro++;
                $this->error("  erro {$canal->instance_name}: ".$e->getMessage());
                Log::error('Nao foi possivel conferir o webhook da Evolution', [
                    'canal' => $canal->id,
                    'erro'  => $e->getMessage(),
                ]);
            }
        }

        /*
         * O selo SO com tudo verificado. Gravar mesmo com erro seria pior que nao gravar:
         * o diagnostico passaria a jurar que o apontamento esta conferido justamente quando
         * ninguem conseguiu conferir.
         */
        if ($comErro === 0) {
            SystemSetting::gravar(self::SELO, now()->toIso8601String());
        }

        $this->newLine();
        $this->info("Conferidos: {$canais->count()} · reapontados: {$corrigidos} · com erro: {$comErro}");

        return $comErro === 0 ? self::SUCCESS : self::FAILURE;
    }
}
