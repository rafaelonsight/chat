<?php

namespace App\Console\Commands;

use App\Services\ImportadorDeConta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 * Traz uma conta de outra instalacao do Virtus (v2, Agenda) para esta, com ids novos.
 *
 * Endereco e chave da origem podem vir do ambiente (IMPORTAR_ORIGEM_URL, IMPORTAR_ORIGEM_KEY)
 * para a senha do banco nao aparecer na lista de processos. Sem --gravar, simula e desfaz:
 * rode assim primeiro, e o que der conflito aparece antes de mexer em qualquer coisa.
 */
class ImportarConta extends Command
{
    protected $signature = 'virtus:importar-conta
        {conta : id da conta no banco de origem}
        {--origem= : nome de uma conexao ou URL postgres da origem (ou IMPORTAR_ORIGEM_URL)}
        {--chave-origem= : APP_KEY da origem, para reabrir tokens criptografados (ou IMPORTAR_ORIGEM_KEY)}
        {--midia-origem= : pasta storage/app da origem, para copiar os arquivos}
        {--gravar : sem isto, simula tudo e desfaz no fim}';

    protected $description = 'Copia uma conta de outra instalacao do Virtus para esta, com ids novos';

    public function handle(): int
    {
        $origem = $this->option('origem') ?: getenv('IMPORTAR_ORIGEM_URL');
        if (! $origem) {
            $this->error('Informe a origem: --origem ou IMPORTAR_ORIGEM_URL.');

            return self::FAILURE;
        }
        if (str_contains($origem, '://')) {
            config(['database.connections.importacao_origem' => array_merge(config('database.connections.pgsql'), ['url' => $origem])]);
            $origem = 'importacao_origem';
        }

        $gravar = (bool) $this->option('gravar');
        $resultado = (new ImportadorDeConta(DB::connection($origem)))
            ->chaveDaOrigem($this->option('chave-origem') ?: (getenv('IMPORTAR_ORIGEM_KEY') ?: null))
            ->midiaDaOrigem($this->option('midia-origem'))
            ->importar((int) $this->argument('conta'), $gravar);

        $this->table(['Tabela', 'Linhas'], collect($resultado['tabelas'])->filter()->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->line("Arquivos: {$resultado['arquivos']}");
        foreach ($resultado['avisos'] as $aviso) {
            $this->warn($aviso);
        }

        $gravar
            ? $this->info("Conta importada com o id {$resultado['conta']}.")
            : $this->info('Simulacao: nada foi gravado. Rode de novo com --gravar.');

        return self::SUCCESS;
    }
}
