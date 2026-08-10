<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\SystemSetting;
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
    /**
     * O que esta rotina cobre, para a tela poder mostrar tambem o que esta BEM.
     *
     * verificar() devolve apenas problemas — e uma tela que so lista problemas nao responde
     * "o que voce olhou?". Sem essa resposta, "tudo certo" nao tranquiliza ninguem: pode
     * significar que esta tudo bem ou que nada foi verificado.
     *
     * As chaves sao as MESMAS que verificar() usa nos problemas. E o encaixe entre as duas
     * listas, e por isso a tela nao precisa repetir nenhuma regra.
     */
    public const COBERTURA = [
        'horizon'        => 'Fila de trabalho (Horizon) rodando',
        'evolution'      => 'Evolution no ar (canal não oficial)',
        'redis'          => 'Redis no ar (a fila depende dele)',
        'reverb'         => 'Reverb no ar (a tela atualiza sozinha)',
        'whisper'        => 'Whisper no ar (transcrição de áudio)',
        'video'          => 'Servidor de vídeo no ar (chamadas)',
        'banco'          => 'Banco de dados acessível',
        'email'          => 'Envio de e-mail configurado',
        'webhook_parado' => 'Mensagem recebida sem processar',
        'webhook_apontamento' => 'Evolution avisando neste servidor',
        'fila'           => 'Fila acumulando trabalho',
        'falhas'         => 'Jobs falhando acima do normal',
        'canais'         => 'Canais conectados',
        'disco'          => 'Espaço em disco',
        'backup'         => 'Backup recente',
    ];

    public function verificar(): array
    {
        $limites = config('onchat.limites');

        return array_values(array_filter([
            $this->horizon(),
            $this->porta('evolution', 8081, self::CRITICO, 'Evolution fora do ar: nao entra nem sai mensagem'),
            $this->porta('redis', 6379, self::CRITICO, 'Redis fora do ar: a fila para'),
            $this->porta('reverb', 8080, self::AVISO, 'Reverb fora do ar: a tela para de atualizar sozinha'),
            $this->porta('whisper', 9090, self::AVISO, 'Whisper fora do ar: audio nao e transcrito'),
            // So olha quando ha credencial: servidor sem video configurado nao esta com
            // defeito, e alerta que acende sempre e alerta que se aprende a ignorar.
            app(\App\Services\Video\Livekit::class)->configurado()
                ? $this->porta('video', 7880, self::AVISO, 'Servidor de video fora do ar: chamada por video nao abre')
                : null,
            $this->banco(),
            $this->email(),
            $this->webhookParado($limites['webhook_parado_minutos']),
            $this->apontamentoSemConferencia($limites['webhook_conferido_minutos']),
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

    /**
     * Da para enviar e-mail?
     *
     * Aviso e nao critico: sem e-mail as mensagens continuam entrando e saindo. Mas
     * recuperacao de senha, convite de usuario e qualquer aviso por e-mail passam a
     * "funcionar" sem chegar a ninguem — a tela diz "enviamos" e o texto vai para o arquivo
     * de log. Falha silenciosa e o pior tipo, e a unica forma de nao ser silenciosa e alguem
     * dizer em voz alta que ela existe.
     */
    private function email(): ?array
    {
        $transporte = (string) config('mail.default');

        if (in_array($transporte, ['', 'log', 'array'], true)) {
            return $this->problema(
                'email',
                self::AVISO,
                'E-mail nao configurado: recuperacao de senha e avisos nao chegam a ninguem, ficam no log.',
            );
        }

        $remetente = (string) config('mail.from.address');

        if ($remetente === '' || str_contains($remetente, 'example.com')) {
            return $this->problema(
                'email',
                self::AVISO,
                'Remetente de e-mail nao definido: provedor de destino recusa mensagem sem remetente valido.',
            );
        }

        // A verificacao que faltava, e a falta dela me pegou: com host, porta, usuario e senha
        // preenchidos, o diagnostico escrevia "Tudo certo" enquanto um envio de verdade voltava
        // 535 senha recusada. Configuracao preenchida nao e prova de nada; a unica prova de que
        // o caminho existe e ter passado por ele.
        if (! SystemSetting::ler('email.ultimo_envio')) {
            return $this->problema(
                'email',
                self::AVISO,
                'E-mail configurado, mas nenhum envio foi aceito pelo servidor ate agora: '
                .'a configuracao pode estar certa e a senha errada.',
            );
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

    /**
     * Ninguem confere ha quanto tempo para onde a Evolution avisa.
     *
     * ESTE E O VIGIA QUE FALTAVA. O webhookParado() acima conta aviso que CHEGOU e nao foi
     * processado: ele ve cano entupido. Cano DESLIGADO da zero, e zero, para ele, e saude —
     * foi assim que dois dias de silencio passaram por aqui sem acender nada.
     *
     * E ele nao pergunta a Evolution: ele cobra PROVA de que alguem perguntou. O selo e
     * gravado pelo onchat:conferir-webhooks so quando todos os canais foram verificados sem
     * erro, entao um selo velho acusa as duas doencas ao mesmo tempo — apontamento errado que
     * nao pudemos corrigir, e o proprio verificador morto. Vigia que fosse verificar por conta
     * propria ficaria cego para o segundo caso, que e o mais traicoeiro dos dois.
     */
    private function apontamentoSemConferencia(int $minutos): ?array
    {
        // Servidor sem canal Evolution nao tem apontamento para conferir, e alerta que acende
        // sempre e alerta que se aprende a ignorar.
        $temEvolution = Channel::withoutGlobalScope('tenant')
            ->where('tipo', Channel::EVOLUTION)
            ->exists();

        if (! $temEvolution) {
            return null;
        }

        $selo = SystemSetting::ler(\App\Console\Commands\ConferirWebhooks::SELO);

        if ($selo !== null && \Illuminate\Support\Carbon::parse($selo)->gt(now()->subMinutes($minutos))) {
            return null;
        }

        return $this->problema(
            'webhook_apontamento',
            self::CRITICO,
            $selo === null
                ? 'Nunca foi conferido para onde a Evolution avisa: mensagem de cliente pode estar caindo fora'
                : 'Ninguem confere para onde a Evolution avisa desde '
                    .\Illuminate\Support\Carbon::parse($selo)->diffForHumans()
                    .': mensagem de cliente pode estar caindo fora sem erro nenhum aparecer',
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
            return $this->problema('backup', self::AVISO, 'Nunca rodou backup do '.config('app.name'));
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
