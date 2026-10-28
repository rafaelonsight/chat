<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\{Crypt, DB, Storage};
use Illuminate\Support\Str;
use RuntimeException;

/*
 * Copia uma conta inteira de outro banco do Virtus (v2, Agenda) para este, com ids novos.
 *
 * Os ids da origem colidem com os daqui, entao nada e copiado como esta: cada linha ganha id novo
 * e toda coluna que aponta para outra linha da conta e traduzida. Quem aponta para quem sai das
 * chaves estrangeiras dos dois bancos; o que o banco nao declara (colunas sem FK, ids dentro de
 * JSON, polimorficas, caminhos de arquivo com id no nome) esta nas listas abaixo, levantadas
 * contra o schema da v2 em 28/10. Tabela nova com referencia sem FK entra aqui, senao o id da
 * origem e copiado cru e aponta para a linha errada.
 *
 * Tudo numa transacao: ou a conta entra inteira ou nada entra. Sem gravar, simula e desfaz.
 */
class ImportadorDeConta
{
    // Infraestrutura, ou o que nao vale fora do dominio antigo (push fica preso a origem do navegador).
    public const IGNORAR = [
        'migrations', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'sessions',
        'password_reset_tokens', 'webhook_events', 'browser_push_subscriptions', 'notifications',
        'codigos_de_acesso', 'portal_otps', 'portal_sessions', 'trial_security_events',
    ];

    // Referencias que o banco nao declara como chave estrangeira.
    public const REFERENCIAS = [
        'compras_tokens_ia.user_id'                     => 'users',
        'consumos_ia.message_id'                        => 'messages',
        'consumos_ia.user_id'                           => 'users',
        'creditos_ia.user_id'                           => 'users',
        'conversations.chatbot_visto_msg_id'            => 'messages',
        'encerramentos_atendimento.canal_id'            => 'channels',
        'encerramentos_atendimento.equipe_id'           => 'teams',
        'encerramentos_atendimento.pesquisa_message_id' => 'messages',
        'encerramentos_atendimento.responsavel_id'      => 'users',
        'encerramentos_atendimento.ultima_mensagem_id'  => 'messages',
        'etiqueta_movimentos.tag_id'                    => 'tags',
        'etiqueta_movimentos.usuario_id'                => 'users',
    ];

    // tabela.coluna_do_tipo => coluna do id
    public const POLIMORFICAS = ['etiqueta_movimentos.alvo_tipo' => 'alvo_id'];

    // Colunas JSON que sao uma lista de ids.
    public const LISTAS_JSON = [
        'chatbots.channel_ids'                 => 'channels',
        'messages.mencionados'                 => 'users',
        'ticket_meetings.participant_user_ids' => 'users',
    ];

    // Chaves que guardam id (ou lista de ids) dentro de qualquer JSON da conta.
    public const CHAVES_JSON = [
        'channel_id' => 'channels', 'channel_ids' => 'channels', 'team_id' => 'teams',
        'chatbot_id' => 'chatbots', 'tag_id' => 'tags', 'tag_ids' => 'tags', 'excluir_tag_id' => 'tags',
        'contact_id' => 'contacts', 'contact_ids' => 'contacts', 'user_id' => 'users', 'user_ids' => 'users',
    ];

    // Arquivos com id no caminho: tabela.coluna => [disco, padrao]. O caminho novo troca conta e id.
    public const ARQUIVOS = [
        'messages.media_path'    => ['local', '#^media/\d+/\d+/(.+)$#', 'media/{conta}/{conversation_id}/$1'],
        'contacts.foto_whatsapp' => ['public', '#^contatos/whatsapp/\d+/\d+\.(\w+)$#', 'contatos/whatsapp/{conta}/{id}.$1'],
    ];

    private const FKS = <<<'SQL'
        select cl.relname as tabela, a.attname as coluna, p.relname as pai
        from pg_constraint c
        join pg_class cl on cl.oid = c.conrelid
        join pg_class p on p.oid = c.confrelid
        join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
        where c.contype = 'f' and array_length(c.conkey, 1) = 1
          and cl.relnamespace = current_schema()::regnamespace
        SQL;

    private ?Encrypter $chaveOrigem = null;

    private ?string $midiaOrigem = null;

    /** @var (callable(string, array): array)|null */
    private $ajuste = null;

    private array $colunas = [];

    private array $refs = [];

    private array $linhas = [];

    private array $adiadas = [];

    /** @var array<string, array<int|string, int|string>> */
    private array $mapa = [];

    private array $pendencias = [];

    private array $arquivos = [];

    private array $avisos = [];

    private array $contagem = [];

    public function __construct(private Connection $origem) {}

    public function chaveDaOrigem(?string $chave): static
    {
        if ($chave) {
            $bruta = str_starts_with($chave, 'base64:') ? base64_decode(substr($chave, 7)) : $chave;
            $this->chaveOrigem = new Encrypter($bruta, config('app.cipher'));
        }

        return $this;
    }

    public function midiaDaOrigem(?string $pasta): static
    {
        $this->midiaOrigem = $pasta ? rtrim($pasta, '/') : null;

        return $this;
    }

    // Ultima palavra antes de gravar cada linha. Serve para resolver conflito conhecido (e o teste
    // usa para copiar uma conta dentro do mesmo banco sem bater no e-mail unico).
    public function ajustarLinha(callable $ajuste): static
    {
        $this->ajuste = $ajuste;

        return $this;
    }

    /** @return array{conta: int, tabelas: array<string, int>, arquivos: int, avisos: list<string>} */
    public function importar(int $conta, bool $gravar): array
    {
        $destino = DB::connection();
        $this->colunas = $this->colunas($destino);
        $colunasOrigem = $this->colunas($this->origem);
        $this->refs = $this->referencias($colunasOrigem, $destino);
        $this->selecionar($conta, $colunasOrigem);

        if (empty($this->linhas['tenants'])) {
            throw new RuntimeException("A conta {$conta} nao existe na origem.");
        }

        $destino->beginTransaction();
        try {
            foreach ($this->ordenar() as $tabela) {
                $this->copiar($tabela, $destino);
            }
            $this->completarAdiadas($destino);
            $novaConta = (int) $this->mapa['tenants'][$conta];
            $this->conferir($novaConta, $destino);

            if ($gravar) {
                $destino->commit();
                $this->copiarArquivos();
            } else {
                $destino->rollBack();
            }
        } catch (\Throwable $e) {
            $destino->rollBack();
            throw $e;
        }

        return ['conta' => $novaConta, 'tabelas' => $this->contagem, 'arquivos' => count($this->arquivos), 'avisos' => $this->avisos];
    }

    private function colunas(Connection $db): array
    {
        $colunas = [];
        foreach ($db->select("select table_name, column_name, data_type, is_nullable from information_schema.columns where table_schema = current_schema()") as $c) {
            $colunas[$c->table_name][$c->column_name] = ['tipo' => $c->data_type, 'nulo' => $c->is_nullable === 'YES'];
        }

        return $colunas;
    }

    private function referencias(array $colunasOrigem, Connection $destino): array
    {
        $refs = [];
        foreach ([$this->origem, $destino] as $db) {
            foreach ($db->select(self::FKS) as $fk) {
                $refs[$fk->tabela][$fk->coluna] = $fk->pai;
            }
        }
        foreach (self::REFERENCIAS as $alvo => $pai) {
            [$tabela, $coluna] = explode('.', $alvo);
            $refs[$tabela][$coluna] = $pai;
        }
        foreach ($colunasOrigem as $tabela => $cols) {
            if (isset($cols['tenant_id'])) {
                $refs[$tabela]['tenant_id'] = 'tenants';
            }
        }

        return $refs;
    }

    // O que e da conta: a linha de tenants, tudo com tenant_id dela e, por tabela sem tenant_id,
    // o que aponta para uma linha ja escolhida (pivos, passos, itens). O resto e global e fica.
    private function selecionar(int $conta, array $colunasOrigem): void
    {
        $pegar = fn ($tabela, $filtro) => array_map(fn ($l) => (array) $l, $this->origem->table($tabela)->where($filtro)->get()->all());

        $this->linhas = ['tenants' => $pegar('tenants', fn ($q) => $q->where('id', $conta))];
        $pendentes = [];
        foreach ($colunasOrigem as $tabela => $cols) {
            if ($tabela === 'tenants' || in_array($tabela, self::IGNORAR, true)) {
                continue;
            }
            if (! isset($this->colunas[$tabela])) {
                $this->avisos[] = "Tabela {$tabela} nao existe aqui: ficou de fora.";
                continue;
            }
            if (isset($cols['tenant_id'])) {
                $this->linhas[$tabela] = $pegar($tabela, fn ($q) => $q->where('tenant_id', $conta));
            } else {
                $pendentes[$tabela] = true;
            }
        }

        do {
            $andou = false;
            foreach (array_keys($pendentes) as $tabela) {
                $pais = $this->refs[$tabela] ?? [];
                if (array_filter($pais, fn ($pai) => $pai !== $tabela && isset($pendentes[$pai]))) {
                    continue;
                }
                unset($pendentes[$tabela]);
                $andou = true;

                $linhas = [];
                foreach ($pais as $coluna => $pai) {
                    $ids = array_column($this->linhas[$pai] ?? [], 'id');
                    foreach (array_chunk($ids, 10000) as $lote) {
                        foreach ($pegar($tabela, fn ($q) => $q->whereIn($coluna, $lote)) as $linha) {
                            $linhas[json_encode($linha)] = $linha;
                        }
                    }
                }
                if ($linhas) {
                    $this->linhas[$tabela] = array_values($linhas);
                }
            }
        } while ($andou && $pendentes);

        foreach (array_keys($pendentes) as $tabela) {
            $this->avisos[] = "Tabela {$tabela} tem referencia circular sem tenant_id: ficou de fora.";
        }
    }

    // Pai antes de filho. Referencia a si mesma, ou ciclo, vira coluna adiada: entra nula e e
    // preenchida depois que todas as linhas existem.
    private function ordenar(): array
    {
        $faltam = [];
        foreach (array_keys($this->linhas) as $tabela) {
            foreach ($this->refs[$tabela] ?? [] as $coluna => $pai) {
                if ($pai === $tabela) {
                    $this->adiar($tabela, $coluna);
                } elseif (isset($this->linhas[$pai])) {
                    $faltam[$tabela][$coluna] = $pai;
                }
            }
            $faltam[$tabela] ??= [];
        }

        $ordem = [];
        while ($faltam) {
            $prontas = array_keys(array_filter($faltam, fn ($pais) => ! $pais));
            if (! $prontas) {
                $this->quebrarCiclo($faltam);
                continue;
            }
            foreach ($prontas as $tabela) {
                $ordem[] = $tabela;
                unset($faltam[$tabela]);
            }
            foreach ($faltam as $tabela => $pais) {
                $faltam[$tabela] = array_filter($pais, fn ($pai) => isset($faltam[$pai]));
            }
        }

        return $ordem;
    }

    // Conversa aponta para mensagem e mensagem para conversa: adia a primeira coluna do ciclo que
    // aceita nulo. Coluna obrigatoria nunca e adiada.
    private function quebrarCiclo(array &$faltam): void
    {
        foreach ($faltam as $tabela => $pais) {
            foreach (array_keys($pais) as $coluna) {
                if ($this->colunas[$tabela][$coluna]['nulo'] ?? false) {
                    $this->adiar($tabela, $coluna);
                    unset($faltam[$tabela][$coluna]);

                    return;
                }
            }
        }
        throw new RuntimeException('Ciclo entre '.implode(', ', array_keys($faltam)).' sem coluna que aceite nulo: nao da para ordenar a copia.');
    }

    private function adiar(string $tabela, string $coluna): void
    {
        if (! ($this->colunas[$tabela][$coluna]['nulo'] ?? true)) {
            throw new RuntimeException("{$tabela}.{$coluna} aponta para a propria tabela e nao aceita nulo.");
        }
        $this->adiadas[$tabela][$coluna] = true;
    }

    private function copiar(string $tabela, Connection $destino): void
    {
        $destinoCols = $this->colunas[$tabela];
        $idNumerico = in_array($destinoCols['id']['tipo'] ?? null, ['bigint', 'integer', 'smallint'], true);
        $copiadas = 0;

        foreach ($this->linhas[$tabela] as $linha) {
            $velho = $linha['id'] ?? null;
            $nova = array_intersect_key($linha, $destinoCols);
            if ($idNumerico) {
                unset($nova['id']);
            }

            $adiadas = [];
            foreach ($this->refs[$tabela] ?? [] as $coluna => $pai) {
                if (! isset($nova[$coluna]) || ! isset($this->linhas[$pai])) {
                    continue; // nulo, ou tabela global: o id vale o mesmo nos dois bancos
                }
                if (isset($this->adiadas[$tabela][$coluna])) {
                    $adiadas[$coluna] = [$pai, $nova[$coluna]];
                    $nova[$coluna] = null;
                    continue;
                }
                $novoId = $this->mapa[$pai][$nova[$coluna]] ?? null;
                if ($novoId === null) {
                    if (! $destinoCols[$coluna]['nulo']) {
                        $this->avisos[] = "{$tabela} {$velho}: aponta para {$pai} {$nova[$coluna]}, que nao veio. Linha pulada.";
                        continue 2;
                    }
                    $this->avisos[] = "{$tabela} {$velho}: {$coluna} apontava para {$pai} {$nova[$coluna]}, que nao veio. Ficou vazio.";
                }
                $nova[$coluna] = $novoId;
            }

            foreach (self::POLIMORFICAS as $alvo => $colunaId) {
                [$t, $colunaTipo] = explode('.', $alvo);
                if ($t === $tabela && isset($nova[$colunaTipo], $nova[$colunaId])) {
                    $pai = $this->tabelaDoTipo($nova[$colunaTipo]);
                    $nova[$colunaId] = $pai ? ($this->mapa[$pai][$nova[$colunaId]] ?? null) : $nova[$colunaId];
                }
            }

            foreach ($nova as $coluna => $valor) {
                if (in_array($destinoCols[$coluna]['tipo'], ['json', 'jsonb'], true) && is_string($valor)) {
                    $nova[$coluna] = $this->traduzirJson($tabela, $coluna, $valor);
                } elseif (is_string($valor)) {
                    $nova[$coluna] = $this->recriptografar($tabela, $coluna, $valor);
                }
            }

            if ($tabela === 'tenants' && $destino->table('tenants')->where('slug', $nova['slug'])->exists()) {
                $this->avisos[] = "O slug {$nova['slug']} ja existe aqui: a conta entrou como {$nova['slug']}-importada.";
                $nova['slug'] .= '-importada';
            }

            if ($this->ajuste) {
                $nova = ($this->ajuste)($tabela, $nova);
            }

            try {
                if ($idNumerico) {
                    $novoId = $destino->table($tabela)->insertGetId($nova);
                } else {
                    $destino->table($tabela)->insert($nova);
                    $novoId = $velho;
                }
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505') {
                    throw new RuntimeException("Ja existe aqui um registro igual a {$tabela} {$velho} da origem: ".($e->errorInfo[2] ?? $e->getMessage()), 0, $e);
                }
                throw $e;
            }

            if ($velho !== null) {
                $this->mapa[$tabela][$velho] = $novoId;
            }
            foreach ($adiadas as $coluna => [$pai, $valorVelho]) {
                $this->pendencias[] = [$tabela, $novoId, $coluna, $pai, $valorVelho];
            }
            $this->renomearArquivos($tabela, $novoId, $nova, $destino);
            $copiadas++;
        }

        $this->contagem[$tabela] = $copiadas;
    }

    private function completarAdiadas(Connection $destino): void
    {
        foreach ($this->pendencias as [$tabela, $id, $coluna, $pai, $valorVelho]) {
            $destino->table($tabela)->where('id', $id)->update([$coluna => $this->mapa[$pai][$valorVelho] ?? null]);
        }
    }

    private function traduzirJson(string $tabela, string $coluna, string $valor): string
    {
        $dados = json_decode($valor, true);
        if (! is_array($dados)) {
            return $valor;
        }

        if (isset(self::LISTAS_JSON["{$tabela}.{$coluna}"]) && array_is_list($dados)) {
            $dados = $this->traduzirIds($dados, self::LISTAS_JSON["{$tabela}.{$coluna}"]);
        } else {
            $this->andarNoJson($dados);
        }

        return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function andarNoJson(array &$no): void
    {
        foreach ($no as $chave => &$valor) {
            if (is_string($chave) && isset(self::CHAVES_JSON[$chave]) && $valor !== null) {
                $valor = $this->traduzirIds($valor, self::CHAVES_JSON[$chave]);
            } elseif (is_array($valor)) {
                $this->andarNoJson($valor);
            }
        }
    }

    // Id que nao veio some da lista (ou vira nulo): apontar para id da origem seria apontar para
    // outra conta aqui.
    private function traduzirIds(mixed $valor, string $tabela): mixed
    {
        if (is_array($valor)) {
            return array_values(array_filter(array_map(fn ($id) => $this->traduzirIds($id, $tabela), $valor), fn ($id) => $id !== null));
        }
        if (! is_numeric($valor)) {
            return $valor;
        }
        $novo = $this->mapa[$tabela][(int) $valor] ?? null;

        return $novo === null ? null : (is_string($valor) ? (string) $novo : (int) $novo);
    }

    // Cada instalacao tem a sua APP_KEY: o que foi criptografado la nao abre aqui.
    private function recriptografar(string $tabela, string $coluna, string $valor): string
    {
        $carga = json_decode((string) base64_decode($valor, true), true);
        if (! is_array($carga) || ! isset($carga['iv'], $carga['value'], $carga['mac'])) {
            return $valor;
        }
        if (! $this->chaveOrigem) {
            $this->avisos[] = "{$tabela}.{$coluna} esta criptografado e veio sem a chave da origem: so abre se a APP_KEY for a mesma.";

            return $valor;
        }
        try {
            return Crypt::encryptString($this->chaveOrigem->decryptString($valor));
        } catch (DecryptException $e) {
            throw new RuntimeException("A chave da origem nao abre {$tabela}.{$coluna}. Confira a APP_KEY informada.", 0, $e);
        }
    }

    private function renomearArquivos(string $tabela, int|string|null $id, array $nova, Connection $destino): void
    {
        foreach (self::ARQUIVOS as $alvo => [$disco, $padrao, $modelo]) {
            [$t, $coluna] = explode('.', $alvo);
            if ($t !== $tabela || empty($nova[$coluna])) {
                continue;
            }
            $caminho = $nova[$coluna];
            if (preg_match($padrao, $caminho)) {
                $troca = strtr($modelo, ['{conta}' => $nova['tenant_id'], '{id}' => $id, '{conversation_id}' => $nova['conversation_id'] ?? '']);
                $novoCaminho = preg_replace($padrao, $troca, $caminho);
                $destino->table($tabela)->where('id', $id)->update([$coluna => $novoCaminho]);
            } else {
                $novoCaminho = $caminho;
            }
            $this->arquivos[] = [$disco, $caminho, $novoCaminho];
        }
    }

    private function copiarArquivos(): void
    {
        if (! $this->arquivos) {
            return;
        }
        if (! $this->midiaOrigem) {
            $this->avisos[] = count($this->arquivos).' arquivos ficaram na origem: informe a pasta storage/app de la.';

            return;
        }
        $faltando = 0;
        foreach ($this->arquivos as [$disco, $de, $para]) {
            $origem = $this->midiaOrigem.'/'.($disco === 'local' ? 'private' : 'public').'/'.$de;
            if (! is_file($origem)) {
                $faltando++;
                continue;
            }
            Storage::disk($disco)->put($para, fopen($origem, 'r'));
        }
        if ($faltando) {
            $this->avisos[] = "{$faltando} arquivos citados no banco nao existem na pasta da origem.";
        }
    }

    // Nenhuma linha da conta nova pode apontar para linha de outra conta.
    private function conferir(int $conta, Connection $destino): void
    {
        foreach (array_keys($this->contagem) as $tabela) {
            if (! isset($this->colunas[$tabela]['tenant_id'])) {
                continue;
            }
            foreach ($this->refs[$tabela] ?? [] as $coluna => $pai) {
                if ($coluna === 'tenant_id' || ! isset($this->colunas[$pai]['tenant_id'], $this->colunas[$pai]['id'], $this->colunas[$tabela][$coluna])) {
                    continue;
                }
                $vazou = $destino->table("{$tabela} as filho")->join("{$pai} as pai", 'pai.id', '=', "filho.{$coluna}")
                    ->where('filho.tenant_id', $conta)->where('pai.tenant_id', '<>', $conta)->count();
                if ($vazou) {
                    throw new RuntimeException("{$vazou} linhas de {$tabela}.{$coluna} apontam para {$pai} de outra conta.");
                }
            }
        }
    }

    private function tabelaDoTipo(string $tipo): ?string
    {
        $classe = Relation::getMorphedModel($tipo) ?? $tipo;

        return class_exists($classe) ? (new $classe)->getTable() : null;
    }
}
