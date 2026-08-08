<?php

use App\Jobs\DispararCampanha;
use App\Jobs\SendTextMessage;
use App\Models\{Campaign, CampaignRecipient, Channel, Contact, Conversation, Message, Tag, Tenant, User};
use App\Services\Disparador;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;

/*
 * Campanhas: mandar a mesma mensagem para muita gente.
 *
 * ESTE ARQUIVO E SOBRE FREIO, e nao sobre alcance. Disparo em massa no canal por QR e o
 * gatilho mais rapido de banimento que existe, e um numero banido leva junto o atendimento
 * INTEIRO do cliente — nao so a campanha. Entao quase todo teste aqui e sobre alguem NAO
 * receber: quem pediu para sair, quem foi bloqueado, grupo, e a madrugada.
 */

beforeEach(function () {
    /*
     * RELOGIO PARADO NUMA TERCA DE MANHA.
     *
     * Metade dos testes daqui e sobre a JANELA DE HORARIO — o freio que impede o disparo de
     * madrugada. Com a hora da maquina, esses testes passavam o dia inteiro e falhavam
     * sozinhos depois das 23h, quando a propria janela que eles configuram fecha. Teste que
     * depende de quando roda nao afirma nada: ele so avisa que ja e tarde.
     */
    $this->travelTo(Illuminate\Support\Carbon::parse('2026-08-11 10:00:00'));

    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'camp']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Gestor',
        'email' => 'gestor@camp.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cam',
    ]);

    $this->etiqueta = Tag::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Clientes', 'cor' => 'verde',
    ]);

    $this->campanha = Campaign::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'criada_por' => $this->pessoa->id, 'nome' => 'Promoção de agosto',
        'publico' => 'etiqueta', 'tag_id' => $this->etiqueta->id,
        'corpo' => 'Temos novidade para você!',
    ]);

    $this->disparador = app(Disparador::class);
    $this->actingAs($this->pessoa);
});

function contatoCamp($ctx, string $nome, array $extra = [], bool $etiquetado = true): Contact
{
    static $n = 0;
    $n++;

    $c = Contact::create(array_merge([
        'tenant_id' => $ctx->conta->id, 'nome' => $nome,
        'telefone_e164' => '+55419900000'.$n, 'jid' => '55419900000'.$n.'@s.whatsapp.net',
    ], $extra));

    if ($etiquetado) {
        $c->tags()->attach($ctx->etiqueta->id);
    }

    return $c;
}

// =============================================================== quem entra

it('so entra quem tem a etiqueta escolhida', function () {
    contatoCamp($this, 'Com etiqueta');
    contatoCamp($this, 'Sem etiqueta', [], etiquetado: false);

    expect($this->disparador->publicoFinal($this->campanha)->count())->toBe(1);
});

it('quem pediu para sair NAO entra', function () {
    contatoCamp($this, 'Fica');
    contatoCamp($this, 'Saiu', ['opt_out_em' => now()]);

    expect($this->disparador->publicoFinal($this->campanha)->count())->toBe(1);
});

it('bloqueado e arquivado nao entram', function () {
    contatoCamp($this, 'Fica');
    contatoCamp($this, 'Bloqueado', ['bloqueado_em' => now()]);
    contatoCamp($this, 'Arquivado', ['arquivado_em' => now()]);

    expect($this->disparador->publicoFinal($this->campanha)->count())->toBe(1);
});

it('GRUPO nunca entra em campanha', function () {
    // Campanha em grupo e spam para dezenas de pessoas que nao pediram nada — e o jeito mais
    // rapido de o numero ser denunciado por gente que nem e cliente.
    contatoCamp($this, 'Pessoa');
    contatoCamp($this, 'Grupo', ['tipo' => 'grupo', 'jid' => '12036330@g.us']);

    expect($this->disparador->publicoFinal($this->campanha)->count())->toBe(1);
});

it('a contagem mostra quantos ficaram DE FORA, e nao so quantos entram', function () {
    // "482 contatos, 41 fora" faz perguntar por que. "441" sozinho nao faz perguntar nada.
    contatoCamp($this, 'Fica');
    contatoCamp($this, 'Saiu', ['opt_out_em' => now()]);
    contatoCamp($this, 'Bloqueado', ['bloqueado_em' => now()]);

    expect($this->disparador->contagem($this->campanha))
        ->toBe(['bruto' => 3, 'final' => 1, 'fora' => 2]);
});

// ============================================================== a fila

it('a fila e congelada ao iniciar, e nao consultada a cada envio', function () {
    // Se fosse consultada a cada envio, quem ganhasse a etiqueta no meio do disparo entraria
    // sem ninguem ter decidido isso.
    contatoCamp($this, 'Um');
    contatoCamp($this, 'Dois');

    expect($this->disparador->montarFila($this->campanha))->toBe(2);

    contatoCamp($this, 'Chegou depois');

    expect($this->campanha->recipients()->count())->toBe(2);
});

it('montar a fila duas vezes nao duplica ninguem', function () {
    contatoCamp($this, 'Um');

    $this->disparador->montarFila($this->campanha);
    $this->disparador->montarFila($this->campanha);

    expect($this->campanha->recipients()->count())->toBe(1);
});

// ========================================================== ritmo e janela

it('espalha os envios conforme o ritmo', function () {
    $this->campanha->update(['por_minuto' => 6]);
    $partida = now()->setTime(10, 0);

    expect($this->disparador->quandoEnviar($this->campanha, 0, $partida)->format('H:i:s'))->toBe('10:00:00')
        ->and($this->disparador->quandoEnviar($this->campanha, 6, $partida)->format('H:i:s'))->toBe('10:01:00');
});

it('empurra para a manha o que cairia de madrugada', function () {
    // Disparo as 23h nao e so falta de educacao: e assedio de consumo no CDC e denuncia no
    // WhatsApp.
    $noite = now()->setTime(23, 30);

    $quando = $this->disparador->dentroDaJanela($this->campanha, $noite);

    expect($quando->hour)->toBe(9)
        ->and($quando->day)->toBe($noite->copy()->addDay()->day);
});

it('empurra para as 9h o que cairia de madrugada cedo', function () {
    $madrugada = now()->setTime(3, 0);

    expect($this->disparador->dentroDaJanela($this->campanha, $madrugada)->hour)->toBe(9);
});

it('o banco recusa ritmo absurdo', function () {
    // 30 por minuto ja e agressivo para um numero pessoal, e o campo chega do navegador.
    expect(fn () => $this->campanha->forceFill(['por_minuto' => 500])->save())
        ->toThrow(Illuminate\Database\QueryException::class);
});

// ============================================================== o disparo

it('manda o lote do minuto e se reagenda', function () {
    Queue::fake();

    foreach (range(1, 10) as $i) {
        contatoCamp($this, 'Cliente '.$i);
    }

    $this->campanha->update(['status' => Campaign::ENVIANDO, 'por_minuto' => 4, 'hora_inicio' => 0, 'hora_fim' => 23]);
    $this->disparador->montarFila($this->campanha);

    (new DispararCampanha($this->campanha->id))->handle($this->disparador);

    expect($this->campanha->recipients()->where('status', CampaignRecipient::ENVIADA)->count())->toBe(4)
        ->and($this->campanha->recipients()->where('status', CampaignRecipient::PENDENTE)->count())->toBe(6);

    Queue::assertPushed(DispararCampanha::class);
    Queue::assertPushed(SendTextMessage::class, 4);
});

it('campanha pausada nao manda nada', function () {
    Queue::fake();
    contatoCamp($this, 'Um');

    $this->campanha->update(['status' => Campaign::ENVIANDO, 'hora_inicio' => 0, 'hora_fim' => 23]);
    $this->disparador->montarFila($this->campanha);
    $this->campanha->update(['status' => Campaign::PAUSADA]);

    (new DispararCampanha($this->campanha->id))->handle($this->disparador);

    expect($this->campanha->recipients()->where('status', CampaignRecipient::PENDENTE)->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('RECONFERE o opt-out na hora de mandar, e nao so quando montou a fila', function () {
    // Entre montar a fila e chegar a vez podem passar horas — e alguem pode ter pedido para
    // sair no meio da campanha, justamente por causa dela.
    Queue::fake();

    $contato = contatoCamp($this, 'Desistiu');

    $this->campanha->update(['status' => Campaign::ENVIANDO, 'hora_inicio' => 0, 'hora_fim' => 23]);
    $this->disparador->montarFila($this->campanha);

    $contato->forceFill(['opt_out_em' => now()])->save();

    (new DispararCampanha($this->campanha->id))->handle($this->disparador);

    $linha = $this->campanha->recipients()->first();

    expect($linha->status)->toBe(CampaignRecipient::PULADA)
        ->and($linha->motivo)->toContain('sair da lista');

    Queue::assertNotPushed(SendTextMessage::class);
});

it('falha de um destinatario nao para a campanha', function () {
    // Parar tudo por um numero invalido faria mil pessoas nao receberem por causa de uma.
    Queue::fake();

    contatoCamp($this, 'Bom');
    $ruim = contatoCamp($this, 'Ruim');
    // contato sem canal valido: forca o erro na criacao da conversa
    $ruim->forceFill(['telefone_e164' => null, 'jid' => 'quebrado'])->save();

    $this->campanha->update(['status' => Campaign::ENVIANDO, 'hora_inicio' => 0, 'hora_fim' => 23]);
    $this->disparador->montarFila($this->campanha);

    (new DispararCampanha($this->campanha->id))->handle($this->disparador);

    expect($this->campanha->recipients()->where('status', CampaignRecipient::ENVIADA)->count())
        ->toBeGreaterThanOrEqual(1);
});

it('marca a campanha como concluida quando nao sobra ninguem', function () {
    Queue::fake();
    contatoCamp($this, 'Um');

    $this->campanha->update(['status' => Campaign::ENVIANDO, 'hora_inicio' => 0, 'hora_fim' => 23]);
    $this->disparador->montarFila($this->campanha);

    (new DispararCampanha($this->campanha->id))->handle($this->disparador);
    (new DispararCampanha($this->campanha->id))->handle($this->disparador);

    expect($this->campanha->fresh()->status)->toBe(Campaign::CONCLUIDA)
        ->and($this->campanha->fresh()->concluida_em)->not->toBeNull();
});

it('a mensagem da campanha e marcada como automatica', function () {
    // Campanha nao e o atendente falando, e nao pode contar como resposta dele no relatorio
    // de tempo.
    Queue::fake();
    contatoCamp($this, 'Um');

    $this->campanha->update(['status' => Campaign::ENVIANDO, 'hora_inicio' => 0, 'hora_fim' => 23]);
    $this->disparador->montarFila($this->campanha);
    (new DispararCampanha($this->campanha->id))->handle($this->disparador);

    expect(Message::where('direcao', 'out')->first()->automatica)->toBeTrue();
});

it('campanha NAO abre a janela de 24h', function () {
    // A janela pertence a quem procurou. Se a campanha abrisse, um disparo daria a impressao
    // de que da para mandar texto livre pelo canal oficial — e a Meta recusaria.
    Queue::fake();
    contatoCamp($this, 'Um');

    $this->campanha->update(['status' => Campaign::ENVIANDO, 'hora_inicio' => 0, 'hora_fim' => 23]);
    $this->disparador->montarFila($this->campanha);
    (new DispararCampanha($this->campanha->id))->handle($this->disparador);

    expect(Conversation::first()->ultima_entrada_em)->toBeNull();
});

// ============================================================== opt-out

it('reconhece o pedido de saida', function () {
    foreach (['PARAR', 'parar', ' Sair ', 'CANCELAR', 'descadastrar', 'stop'] as $texto) {
        expect($this->disparador->pedidoDeSaida($texto))->toBeTrue("deveria aceitar: {$texto}");
    }
});

it('NAO tira da lista quem so usou a palavra numa frase', function () {
    // "Nao quero parar de receber" contem "parar". Tirar essa pessoa seria errar contra quem
    // acabou de dizer que queria continuar.
    foreach ([
        'nao quero parar de receber',
        'pode parar de mandar boleto errado',
        'vou cancelar minha consulta de amanha',
        'bom dia',
    ] as $texto) {
        expect($this->disparador->pedidoDeSaida($texto))->toBeFalse("nao deveria aceitar: {$texto}");
    }
});

it('quem pediu para sair fica marcado com data e motivo', function () {
    $contato = contatoCamp($this, 'Saiu');

    $this->disparador->marcarSaida($contato);

    expect($contato->fresh()->saiuDaLista())->toBeTrue()
        ->and($contato->fresh()->opt_out_motivo)->not->toBeNull();
});

it('pedir para sair duas vezes nao reescreve a primeira data', function () {
    $contato = contatoCamp($this, 'Saiu', ['opt_out_em' => now()->subDays(3), 'opt_out_motivo' => 'primeira vez']);

    $this->disparador->marcarSaida($contato, 'segunda vez');

    expect($contato->fresh()->opt_out_motivo)->toBe('primeira vez');
});
