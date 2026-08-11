<?php

use App\Models\{Channel, Contact, Conversation, Team, Tenant, User};
use App\Support\TenantContext;

/*
 * A EQUIPE TRIAGEM, PADRAO EM TODA LICENCA.
 *
 * Ela existe por causa de uma consequencia: quando time virou permissao (quem esta num time nao
 * ve conversa sem time), a conversa que acabou de chegar passou a nascer INVISIVEL para todo
 * atendente — visivel so para administrador, que e justamente quem nao atende.
 *
 * A Triagem e o endereco da fila de entrada. Sem ela, o produto ganha uma porta de entrada que
 * ninguem enxerga.
 */

afterEach(fn () => TenantContext::forget());

it('toda licenca nova nasce com a Triagem', function () {
    // No modelo e nao no formulario: licenca tambem nasce por seeder, comando e teste, e equipe
    // que depende de alguem marcar uma caixinha e equipe que vai faltar na conta criada com
    // pressa.
    $conta = Tenant::create(['nome' => 'Nova', 'slug' => 'tri1']);

    $times = Team::withoutGlobalScope('tenant')->where('tenant_id', $conta->id)->pluck('nome')->all();

    expect($times)->toBe([Team::TRIAGEM])
        ->and(Team::triagemDe($conta->id))->not->toBeNull();
});

it('conversa nova cai na Triagem', function () {
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri2']);
    TenantContext::set($conta->id);

    $canal = Channel::create(['nome' => 'Vendas'])->refresh();
    $contato = Contact::create(['jid' => '5511777@s.whatsapp.net', 'tipo' => Contact::PESSOA]);

    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    expect($conversa->team_id)->toBe(Team::triagemDe($conta->id)->id);
});

it('quem ja escolheu o time nao e sobrescrito', function () {
    // Chatbot, transferencia e campanha criam a conversa sabendo o time. O gancho so preenche
    // quando ninguem escolheu.
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri3']);
    TenantContext::set($conta->id);

    $financeiro = Team::create(['nome' => 'Financeiro']);
    $canal = Channel::create(['nome' => 'Vendas'])->refresh();
    $contato = Contact::create(['jid' => '5511666@s.whatsapp.net', 'tipo' => Contact::PESSOA]);

    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id,
        'team_id' => $financeiro->id, 'ultima_msg_em' => now(),
    ]);

    expect($conversa->team_id)->toBe($financeiro->id);
});

it('Triagem apagada nao impede a mensagem de entrar', function () {
    /*
     * Trocar "conversa sem time" por "mensagem perdida" seria um pessimo negocio. Se a equipe
     * foi apagada, a conversa nasce sem time e o recebimento segue — o pior caso e uma conversa
     * que precisa ser direcionada a mao, nao um cliente sem resposta.
     */
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri4']);
    TenantContext::set($conta->id);

    Team::withoutGlobalScope('tenant')->where('tenant_id', $conta->id)->delete();

    $canal = Channel::create(['nome' => 'Vendas'])->refresh();
    $contato = Contact::create(['jid' => '5511555@s.whatsapp.net', 'tipo' => Contact::PESSOA]);

    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    expect($conversa->team_id)->toBeNull();
});

it('o atendente da Triagem ve a fila de entrada', function () {
    /*
     * O TESTE QUE LIGA AS DUAS PONTAS, e a razao de tudo isto existir.
     *
     * Antes da Triagem, este cenario dava lista vazia: a atendente esta num time, e quem esta
     * num time nao ve conversa sem time. Ela ficaria olhando uma tela vazia com gente esperando
     * do outro lado.
     */
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri5']);
    TenantContext::set($conta->id);

    $ana = User::create([
        'tenant_id' => $conta->id, 'name' => 'Ana', 'email' => 'ana@tri5.test',
        'password' => 'segredo123',
    ]);

    $ana->teams()->attach(Team::triagemDe($conta->id)->id);

    $canal = Channel::create(['nome' => 'Vendas'])->refresh();
    $contato = Contact::create(['jid' => '5511444@s.whatsapp.net', 'tipo' => Contact::PESSOA]);

    $chegou = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    test()->actingAs($ana);

    expect(Conversation::pluck('id')->all())->toBe([$chegou->id])
        ->and($ana->podeVer($chegou->fresh()))->toBeTrue();
});
