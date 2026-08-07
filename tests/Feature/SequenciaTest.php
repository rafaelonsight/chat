<?php

use App\Jobs\AvancarSequencias;
use App\Jobs\ProcurarSumidos;
use App\Jobs\SendTextMessage;
use App\Models\{Channel, Contact, Conversation, Message, Sequence, SequenceEnrollment, SequenceStep, Tenant, User};
use App\Services\Cadenciador;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Queue;

/*
 * Sequencias: mensagens em cadencia, disparadas por um gatilho.
 *
 * A DIFERENCA PARA CAMPANHA: campanha e UM disparo para MUITOS ao mesmo tempo; sequencia e
 * VARIAS mensagens para UMA pessoa ao longo do tempo.
 *
 * A REGRA QUE MANDA, e que a maioria destes testes protege: PARA QUANDO O CLIENTE RESPONDE.
 * Sem isso a sequencia vira perseguicao — ele responde, alguem atende, e a maquina continua
 * mandando "notou que voce nao respondeu?" no dia seguinte.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'seq']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Gestor',
        'email' => 'gestor@seq.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'seq',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->sequencia = Sequence::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'nome' => 'Boas-vindas', 'gatilho' => Sequence::PRIMEIRA_CONVERSA,
        'ativa' => true, 'hora_inicio' => 0, 'hora_fim' => 23,
    ]);

    SequenceStep::create(['sequence_id' => $this->sequencia->id, 'ordem' => 1, 'atraso_horas' => 1, 'corpo' => 'Bem-vindo!']);
    SequenceStep::create(['sequence_id' => $this->sequencia->id, 'ordem' => 2, 'atraso_horas' => 24, 'corpo' => 'Precisa de ajuda?']);

    $this->cadenciador = app(Cadenciador::class);
    $this->actingAs($this->pessoa);
    Queue::fake();
});

// ============================================================== inscricao

it('inscreve no gatilho e agenda o primeiro passo', function () {
    expect($this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato))->toBe(1);

    $i = SequenceEnrollment::first();

    expect($i->status)->toBe(SequenceEnrollment::ATIVA)
        ->and($i->proximo_passo)->toBe(1)
        ->and($i->proximo_em)->not->toBeNull();
});

it('sequencia desligada nao inscreve ninguem', function () {
    $this->sequencia->update(['ativa' => false]);

    expect($this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato))->toBe(0);
});

it('sequencia SEM MENSAGEM nao inscreve', function () {
    // Seria uma jornada vazia, "ativa" para sempre, aparecendo nos numeros como se fizesse algo.
    $this->sequencia->steps()->delete();

    expect($this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato))->toBe(0);
});

it('nao inscreve duas vezes a mesma pessoa', function () {
    // Um cliente que abre tres conversas na semana receberia a mesma jornada tres vezes em
    // paralelo — e a culpa pareceria do sistema, porque seria.
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);

    expect(SequenceEnrollment::count())->toBe(1);
});

it('quem pediu para sair, esta bloqueado, arquivado ou e grupo nao entra', function () {
    foreach ([
        ['opt_out_em' => now()],
        ['bloqueado_em' => now()],
        ['arquivado_em' => now()],
        ['tipo' => 'grupo'],
    ] as $i => $marca) {
        $c = Contact::create(array_merge([
            'tenant_id' => $this->conta->id, 'nome' => 'X'.$i,
            'telefone_e164' => '+554188880'.$i.'00', 'jid' => '554188880'.$i.'00@s.whatsapp.net',
        ], $marca));

        expect($this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $c))->toBe(0);
    }
});

// ==================================================== a regra que manda

it('o cliente respondendo PARA a jornada', function () {
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);

    expect($this->cadenciador->clienteRespondeu($this->contato))->toBe(1);

    $i = SequenceEnrollment::first();

    expect($i->status)->toBe(SequenceEnrollment::PARADA)
        ->and($i->parada_motivo)->toContain('respondeu')
        ->and($i->proximo_em)->toBeNull();
});

it('quem desligou o "parar ao responder" continua recebendo', function () {
    // E escolha explicita de quem configurou, com aviso na tela. Nao e o padrao.
    $this->sequencia->update(['parar_ao_responder' => false]);
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);

    $this->cadenciador->clienteRespondeu($this->contato);

    expect(SequenceEnrollment::first()->status)->toBe(SequenceEnrollment::ATIVA);
});

// ============================================================== o tique

it('manda o passo vencido e agenda o proximo', function () {
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);
    SequenceEnrollment::first()->forceFill(['proximo_em' => now()->subMinute()])->save();

    (new AvancarSequencias)->handle($this->cadenciador);

    $i = SequenceEnrollment::first();

    expect(Message::where('direcao', 'out')->first()->corpo)->toBe('Bem-vindo!')
        ->and($i->proximo_passo)->toBe(2)
        ->and($i->status)->toBe(SequenceEnrollment::ATIVA);

    Queue::assertPushed(SendTextMessage::class, 1);
});

it('nao manda antes da hora', function () {
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);

    (new AvancarSequencias)->handle($this->cadenciador);

    expect(Message::count())->toBe(0);
});

it('conclui depois do ultimo passo', function () {
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);

    foreach ([1, 2] as $vez) {
        SequenceEnrollment::first()->forceFill(['proximo_em' => now()->subMinute()])->save();
        (new AvancarSequencias)->handle($this->cadenciador);
    }

    expect(SequenceEnrollment::first()->status)->toBe(SequenceEnrollment::CONCLUIDA)
        ->and(Message::where('direcao', 'out')->count())->toBe(2);
});

it('RECONFERE o opt-out entre um passo e outro', function () {
    // Entre dois passos passam dias, e nesses dias a pessoa pode ter pedido para sair —
    // justamente por causa da sequencia.
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);
    SequenceEnrollment::first()->forceFill(['proximo_em' => now()->subMinute()])->save();

    $this->contato->forceFill(['opt_out_em' => now()])->save();

    (new AvancarSequencias)->handle($this->cadenciador);

    expect(SequenceEnrollment::first()->status)->toBe(SequenceEnrollment::PARADA)
        ->and(Message::count())->toBe(0);
});

it('desligar a sequencia para quem esta no meio dela', function () {
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);
    SequenceEnrollment::first()->forceFill(['proximo_em' => now()->subMinute()])->save();

    $this->sequencia->update(['ativa' => false]);

    (new AvancarSequencias)->handle($this->cadenciador);

    expect(SequenceEnrollment::first()->status)->toBe(SequenceEnrollment::PARADA)
        ->and(Message::count())->toBe(0);
});

it('a mensagem da sequencia e automatica', function () {
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);
    SequenceEnrollment::first()->forceFill(['proximo_em' => now()->subMinute()])->save();

    (new AvancarSequencias)->handle($this->cadenciador);

    expect(Message::where('direcao', 'out')->first()->automatica)->toBeTrue();
});

// ================================================== a janela de horario

it('empurra para a manha o passo que cairia de madrugada', function () {
    $this->sequencia->update(['hora_inicio' => 9, 'hora_fim' => 20]);

    $quando = $this->cadenciador->quando($this->sequencia, 1, now()->setTime(23, 0));

    expect($quando->hour)->toBe(9);
});

// ============================================================ os sumidos

it('acha quem sumiu depois de a gente responder', function () {
    $sumidos = Sequence::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'nome' => 'Voltar', 'gatilho' => Sequence::SEM_RESPOSTA,
        'ativa' => true, 'sem_resposta_horas' => 24, 'hora_inicio' => 0, 'hora_fim' => 23,
    ]);
    SequenceStep::create(['sequence_id' => $sumidos->id, 'ordem' => 1, 'atraso_horas' => 0, 'corpo' => 'Ainda precisa?']);

    $conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'ultima_msg_em' => now()->subHours(30),
    ]);

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'ja respondi', 'status' => Message::STATUS_SENT,
    ]);

    (new ProcurarSumidos)->handle($this->cadenciador);

    expect(SequenceEnrollment::where('sequence_id', $sumidos->id)->count())->toBe(1);
});

it('NAO cobra quem esta esperando resposta NOSSA', function () {
    // Se a ultima mensagem foi dele, quem esta devendo somos nos. Cobrar o cliente nesse caso
    // e ofensivo.
    $sumidos = Sequence::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'nome' => 'Voltar', 'gatilho' => Sequence::SEM_RESPOSTA,
        'ativa' => true, 'sem_resposta_horas' => 24, 'hora_inicio' => 0, 'hora_fim' => 23,
    ]);
    SequenceStep::create(['sequence_id' => $sumidos->id, 'ordem' => 1, 'atraso_horas' => 0, 'corpo' => 'Ainda precisa?']);

    $conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'ultima_msg_em' => now()->subHours(30),
    ]);

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => 'e ai?', 'status' => Message::STATUS_DELIVERED,
    ]);

    (new ProcurarSumidos)->handle($this->cadenciador);

    expect(SequenceEnrollment::where('sequence_id', $sumidos->id)->count())->toBe(0);
});

it('conversa encerrada nao entra em "sem resposta"', function () {
    // Ela tem a jornada de pos-atendimento; as duas juntas seriam duas mensagens dizendo
    // coisas diferentes no mesmo dia.
    $sumidos = Sequence::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'nome' => 'Voltar', 'gatilho' => Sequence::SEM_RESPOSTA,
        'ativa' => true, 'sem_resposta_horas' => 24, 'hora_inicio' => 0, 'hora_fim' => 23,
    ]);
    SequenceStep::create(['sequence_id' => $sumidos->id, 'ordem' => 1, 'atraso_horas' => 0, 'corpo' => 'Ainda precisa?']);

    // Arquiva DEPOIS de criar a mensagem: gravar uma mensagem numa conversa reabre ela, que
    // e o comportamento certo do produto. Meu cenario e que estava errado — criei arquivada,
    // mandei a mensagem, e ela voltou para em_atendimento sem eu perceber.
    $conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
    ]);

    Message::create([
        'tenant_id' => $this->conta->id, 'conversation_id' => $conversa->id,
        'channel_id' => $this->canal->id, 'direcao' => 'out', 'tipo' => 'text',
        'corpo' => 'fechado', 'status' => Message::STATUS_SENT,
    ]);

    $conversa->forceFill([
        'status' => Conversation::ARQUIVADA,
        'ultima_msg_em' => now()->subHours(30),
    ])->save();

    (new ProcurarSumidos)->handle($this->cadenciador);

    expect(SequenceEnrollment::where('sequence_id', $sumidos->id)->count())->toBe(0);
});

// ================================================================= a tela

it('a tela salva a sequencia com os passos', function () {
    Livewire\Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Sequencias::class)
        ->call('nova')
        ->set('nome', 'Pós-venda')
        ->set('channel_id', $this->canal->id)
        ->set('gatilho', Sequence::ATENDIMENTO_ENCERRADO)
        ->set('passos', [
            ['atraso_horas' => 2, 'corpo' => 'Tudo certo?'],
            ['atraso_horas' => 48, 'corpo' => 'Podemos ajudar em mais algo?'],
        ])
        ->call('salvar')
        ->assertHasNoErrors();

    $nova = Sequence::where('nome', 'Pós-venda')->first();

    expect($nova)->not->toBeNull()
        ->and($nova->steps)->toHaveCount(2)
        ->and($nova->steps->first()->ordem)->toBe(1)
        ->and($nova->ativa)->toBeFalse();
});

it('nao liga sequencia sem mensagem', function () {
    $vazia = Sequence::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'nome' => 'Vazia', 'gatilho' => Sequence::PRIMEIRA_CONVERSA,
    ]);

    Livewire\Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Sequencias::class)
        ->call('alternarAtiva', $vazia->id)
        ->assertHasErrors('sequencia');

    expect($vazia->fresh()->ativa)->toBeFalse();
});

it('nao exclui sequencia com gente no meio', function () {
    $this->cadenciador->inscrever(Sequence::PRIMEIRA_CONVERSA, $this->contato);

    Livewire\Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Sequencias::class)
        ->call('excluir', $this->sequencia->id)
        ->assertHasErrors('sequencia');

    expect(Sequence::find($this->sequencia->id))->not->toBeNull();
});
