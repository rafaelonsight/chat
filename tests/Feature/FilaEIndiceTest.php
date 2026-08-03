<?php

use App\Jobs\TranscribeAudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('manda a transcricao para uma fila propria, nao para a default', function () {
    Queue::fake();

    TranscribeAudio::dispatch(1);

    // O que importa nao e o nome da fila, e nao ser a default: audio longo na
    // fila da entrega atrasa mensagem nova de cliente.
    Queue::assertPushedOn('transcricao', TranscribeAudio::class);
});

it('declara o supervisor da fila lenta em todos os ambientes do horizon', function () {
    $config = config('horizon');

    expect($config['defaults'])->toHaveKey('supervisor-transcricao');
    expect($config['defaults']['supervisor-transcricao']['queue'])->toBe(['transcricao']);
    expect($config['defaults']['supervisor-1']['queue'])->toBe(['default']);

    // Sem declarar por ambiente, o Horizon em producao nao sobe o supervisor e a
    // fila fica parada em silencio — pior que nao ter separado.
    foreach (array_keys($config['environments']) as $ambiente) {
        expect($config['environments'][$ambiente])->toHaveKey('supervisor-transcricao');
    }
});

it('da mais tempo e memoria ao worker da transcricao do que ao da entrega', function () {
    $entrega = config('horizon.defaults.supervisor-1');
    $lenta   = config('horizon.defaults.supervisor-transcricao');

    // O job declara timeout de 300s; um supervisor com 60s mataria a transcricao
    // no meio e ela nunca terminaria.
    expect($lenta['timeout'])->toBeGreaterThan((new TranscribeAudio(1))->timeout);
    expect($lenta['nice'])->toBeGreaterThan($entrega['nice']);
});

it('cria os indices de trigrama que a busca do inbox precisa', function () {
    $indices = DB::table('pg_indexes')
        ->whereIn('tablename', ['messages', 'contacts'])
        ->pluck('indexname')
        ->all();

    expect($indices)->toContain('messages_corpo_trgm')
        ->toContain('messages_legenda_trgm')
        ->toContain('messages_transcricao_trgm')
        ->toContain('contacts_nome_trgm');
});

it('usa o indice de trigrama em vez de varrer a tabela na busca', function () {
    // Prova que o indice serve para o padrao que o inbox realmente emite.
    // Sem enable_seqscan=off o Postgres escolhe varredura porque a tabela e
    // pequena, e o teste passaria sem provar nada.
    DB::statement('set enable_seqscan = off');

    $plano = collect(DB::select("explain select id from messages where corpo ilike '%fibra%'"))
        ->map(fn ($linha) => (array) $linha)
        ->map(fn ($linha) => reset($linha))
        ->implode(' ');

    DB::statement('set enable_seqscan = on');

    expect($plano)->toContain('messages_corpo_trgm');
});

it('nao deixa indice repetido em conversations', function () {
    // Eu criei dois indices que ja existiam com outro nome. Este teste existe
    // para o erro nao voltar: indice repetido custa escrita e nao acelera nada.
    $repetidos = DB::table('pg_indexes')
        ->where('tablename', 'conversations')
        ->whereIn('indexname', ['conversations_tenant_ultima_msg_idx', 'conversations_tenant_status_idx'])
        ->count();

    expect($repetidos)->toBe(0);
});
