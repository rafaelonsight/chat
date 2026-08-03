<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\WebhookEvent;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

// Responde uma pergunta: tem algo quebrado agora? Ninguem le log de servidor por
// esporte — o valor esta em transformar isso em alerta e em /saude, que um monitor
// externo consegue vigiar.
class Diagnostico
{
    public const CRITICO = 'critico';

    public const AVISO = 'aviso';

    /**
     * A sonda de porta e injetavel porque em teste as portas de verdade estao
     * abertas nesta maquina, e um teste que depende disso nao prova nada.
     */
    public function __construct(private ?Closure $sonda = null) {}

    /** @return array<int, array{chave: string, nivel: string, mensagem: string}> */
    public function verificar(): array
    {
        $limites = config('onchat.limites');

        return array_values(array_filter([
            $this->horizon(),
            $this->porta('evolution', 8081, self::CRITICO, 'Evolution fora do ar: nao entra nem sai mensagem'),
            $this->porta('redis', 6379, self::CRITICO, 'Redis fora do ar: a fila para'),
            $this->porta('reverb', 8080, self::AVISO, 'Reverb fora do ar: a tela para de atualizar sozinha'),
            $this->porta('whisper', 9090, self::AVISO, 'Whisper fora do ar: audio nao e transcrito'),
            $this->banco(),
            $this->webhookParado($limites['webhook_parado_minutos']),
            $this->filaAcumulada($limites['fila_acumulada']),
            $this->falhas($limites['falhas_por_hora']),
            $this->canaisDesconectados(),
            $this->disco($limites['disco_aviso'], $limites['disco_critico']),
            $this->backupAtrasado($limites['backup_horas']),
        ]));
    }

    public function criticos(): array
    {
        return array_values(array_filter(
            $this->verificar(),
            fn (array $p) => $p['nivel'] === self::CRITICO,
        ));
    }

    // ---------------------------------------------------------------- verificacoes

    private function horizon(): ?array
    {
        try {
            $mestres = app(MasterSupervisorRepository::class)->all();
        } catch (\Throwable $e) {
            return $this->problema('horizon', self::CRITICO, 'Nao consegui falar com o Horizon: '.$e->getMessage());
        }

        if ($mestres === [] || $mestres === null) {
            // O pior defeito silencioso do sistema: o webhook responde 200, o job
            // entra na fila e ninguem o executa. Para quem esta de fora, parece
            // que o cliente nunca escreveu.
            return $this->problema('horizon', self::CRITICO, 'Horizon parado: mensagem entra na fila e ninguem processa');
        }

        return null;
    }

    private function porta(string $chave, int $porta, string $nivel, string $mensagem): ?array
    {
        return $this->portaAberta('127.0.0.1', $porta)
            ? null
            : $this->problema($chave, $nivel, $mensagem);
    }

    private function portaAberta(string $host, int $porta): bool
    {
        if ($this->sonda) {
            return (bool) ($this->sonda)($host, $porta);
        }

        $conexao = @fsockopen($host, $porta, $erro, $texto, 2);

        if ($conexao === false) {
            return false;
        }

        fclose($conexao);

        return true;
    }

    private function banco(): ?array
    {
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            return $this->problema('banco', self::CRITICO, 'Banco inacessivel: '.$e->getMessage());
        }

        return null;
    }

    private function webhookParado(int $minutos): ?array
    {
        // withoutGlobalScope porque diagnostico nao tem tenant: precisa ver tudo.
        $paradas = WebhookEvent::withoutGlobalScope('tenant')
            ->whereNull('processado_em')
            ->where('recebido_em', '<', now()->subMinutes($minutos))
            ->count();

        if ($paradas === 0) {
            return null;
        }

        return $this->problema(
            'webhook_parado',
            self::CRITICO,
            "{$paradas} mensagem(ns) recebida(s) ha mais de {$minutos} min sem processar",
        );
    }

    private function filaAcumulada(int $limite): ?array
    {
        try {
            $total = Queue::size('default') + Queue::size('transcricao');
        } catch (\Throwable $e) {
            return null; // sem fila acessivel, o alerta do Redis ja cobre
        }

        return $total > $limite
            ? $this->problema('fila', self::AVISO, "Fila acumulada: {$total} trabalhos esperando")
            : null;
    }

    private function falhas(int $limite): ?array
    {
        try {
            $total = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
        } catch (\Throwable $e) {
            return null;
        }

        return $total >= $limite
            ? $this->problema('falhas', self::AVISO, "{$total} trabalho(s) falharam na ultima hora")
            : null;
    }

    private function canaisDesconectados(): ?array
    {
        // Sem orWhereNull de proposito: a coluna status e NOT NULL no banco, com
        // valor padrao. Eu tinha posto a defesa contra NULL achando que canal
        // novo escaparia do != 'open'; o teste mostrou que esse estado nao existe,
        // e defesa contra o impossivel so engana quem le depois.
        $desconectados = Channel::withoutGlobalScope('tenant')
            ->where('status', '!=', 'open')
            ->pluck('nome');

        if ($desconectados->isEmpty()) {
            return null;
        }

        return $this->problema(
            'canais',
            self::CRITICO,
            'Canal desconectado: '.$desconectados->implode(', '),
        );
    }

    private function disco(int $aviso, int $critico): ?array
    {
        $total = @disk_total_space('/');
        $livre = @disk_free_space('/');

        if (! $total || $livre === false) {
            return null;
        }

        $usado = (int) round((1 - $livre / $total) * 100);

        if ($usado >= $critico) {
            return $this->problema('disco', self::CRITICO, "Disco em {$usado}%");
        }

        return $usado >= $aviso
            ? $this->problema('disco', self::AVISO, "Disco em {$usado}%")
            : null;
    }

    // Pior que nao ter backup e acreditar que tem. O diretorio de backup e 700
    // root, entao o script grava um carimbo legivel e o que se vigia e a idade
    // dele.
    private function backupAtrasado(int $horas): ?array
    {
        $carimbo = '/var/lib/onchat/ultimo-backup';

        if (! is_readable($carimbo)) {
            return $this->problema('backup', self::AVISO, 'Nunca rodou backup do OnChat');
        }

        $quando = (int) trim((string) @file_get_contents($carimbo));
        $idade = (int) floor((time() - $quando) / 3600);

        return $idade > $horas
            ? $this->problema('backup', self::AVISO, "Ultimo backup ha {$idade}h")
            : null;
    }

    private function problema(string $chave, string $nivel, string $mensagem): array
    {
        return compact('chave', 'nivel', 'mensagem');
    }
}
