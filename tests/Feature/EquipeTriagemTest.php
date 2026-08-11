<?php

use App\Exceptions\EquipePadraoProtegida;
use App\Models\{Channel, Contact, Conversation, Team, Tenant, User};
use App\Support\TenantContext;

/*
 * A EQUIPE PADRAO DE CADA LICENCA — que nasce chamada Triagem.
 *
 * POR QUE ELA EXISTE: quando equipe virou permissao (quem esta num time nao ve conversa sem
 * time), a conversa que acabava de chegar passou a nascer INVISIVEL para todo atendente —
 * visivel so para administrador, que e justamente quem nao atende. Ela e o endereco dessa fila.
 *
 * E POR QUE POR MARCA E NAO POR NOME: a primeira versao achava a equipe procurando 'Triagem'.
 * Bastava renomear para "Recepcao" e a fila de entrada quebrava EM SILENCIO. A coluna 'padrao'
 * conserta isso, e e ela que sustenta o pedido do Rafael: a padrao nao se apaga.
 */

afterEach(fn () => TenantContext::forget());

function canalEContato(string $jid): array
{
    $canal = Channel::create(['nome' => 'Vendas'])->refresh();
    $contato = Contact::create(['jid' => $jid, 'tipo' => Contact::PESSOA]);

    return [$canal, $contato];
}

it('toda licenca nova nasce com a equipe padrao', function () {
    $conta = Tenant::create(['nome' => 'Nova', 'slug' => 'tri1']);

    $padrao = Team::padraoDe($conta->id);

    expect($padrao)->not->toBeNull()
        ->and($padrao->nome)->toBe(Team::TRIAGEM)
        ->and($padrao->padrao)->toBeTrue();
});

it('conversa nova cai na equipe padrao', function () {
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri2']);
    TenantContext::set($conta->id);

    [$canal, $contato] = canalEContato('5511777@s.whatsapp.net');

    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    expect($conversa->team_id)->toBe(Team::padraoDe($conta->id)->id);
});

it('renomear a equipe padrao NAO quebra a fila de entrada', function () {
    /*
     * O TESTE DO DEFEITO QUE A MARCA CONSERTOU.
     *
     * Com a busca por nome, renomear derrubava tudo em silencio: a conversa passava a nascer sem
     * equipe, e ninguem receberia erro nenhum — so pararia de aparecer para os atendentes.
     */
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri3']);
    TenantContext::set($conta->id);

    $padrao = Team::padraoDe($conta->id);
    $padrao->update(['nome' => 'Recepcao']);

    [$canal, $contato] = canalEContato('5511666@s.whatsapp.net');

    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    expect($conversa->team_id)->toBe($padrao->id)
        ->and(Team::padraoDe($conta->id)->nome)->toBe('Recepcao');
});

it('quem ja escolheu a equipe nao e sobrescrito', function () {
    // Chatbot, transferencia e campanha criam a conversa sabendo a equipe.
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri4']);
    TenantContext::set($conta->id);

    $financeiro = Team::create(['nome' => 'Financeiro']);
    [$canal, $contato] = canalEContato('5511555@s.whatsapp.net');

    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id,
        'team_id' => $financeiro->id, 'ultima_msg_em' => now(),
    ]);

    expect($conversa->team_id)->toBe($financeiro->id);
});

// ------------------------------------------------------- a protecao que o Rafael pediu

it('a equipe padrao NAO pode ser apagada', function () {
    /*
     * "A equipe Triagem e padrao e nao pode excluir" — pedido dele, e a razao e concreta:
     * apagar essa equipe nao quebra uma tela, apaga a porta de entrada do atendimento.
     *
     * A guarda vive no MODELO e nao no botao: a exclusao tambem acontece por console, por seeder
     * e por codigo futuro. Regra que mora no botao e regra que o proximo caminho ignora.
     */
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri5']);
    TenantContext::set($conta->id);

    $padrao = Team::padraoDe($conta->id);

    expect(fn () => $padrao->delete())->toThrow(EquipePadraoProtegida::class);

    expect(Team::padraoDe($conta->id))->not->toBeNull();
});

it('a equipe padrao nao pode ser desativada', function () {
    // Desativar quebra igual a apagar: ela sai dos filtros e da transferencia, mas a conversa
    // nova continua caindo nela — mensagem entrando num lugar que ninguem consegue abrir.
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri6']);
    TenantContext::set($conta->id);

    $padrao = Team::padraoDe($conta->id);

    expect(fn () => $padrao->update(['ativa' => false]))->toThrow(EquipePadraoProtegida::class);

    expect(Team::padraoDe($conta->id)->fresh()->ativa)->toBeTrue();
});

it('as OUTRAS equipes continuam podendo ser apagadas', function () {
    // A protecao e da padrao, e nao da tela de equipes: travar tudo seria trocar um problema
    // por outro.
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri7']);
    TenantContext::set($conta->id);

    Team::create(['nome' => 'Suporte'])->delete();

    expect(Team::count())->toBe(1)
        ->and(Team::first()->padrao)->toBeTrue();
});

it('so existe UMA padrao por conta, garantido pelo banco', function () {
    // Duas padroes viraria uma escolha silenciosa de "qual delas" em cada consulta.
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri8']);
    TenantContext::set($conta->id);

    // forceFill de proposito: 'padrao' esta fora do fillable, entao passar pelo create seria
    // descartado em silencio e o teste passaria sem testar o indice.
    expect(fn () => Team::create(['nome' => 'Outra padrao'])->forceFill(['padrao' => true])->save())
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('padrao ausente nao impede a mensagem de entrar', function () {
    /*
     * Cinto e suspensorio: a guarda impede a exclusao pela aplicacao, mas se a linha desaparecer
     * por fora (limpeza de banco, restauracao antiga), a conversa nasce sem equipe e o
     * recebimento SEGUE. Trocar "conversa a direcionar" por "mensagem perdida" seria um pessimo
     * negocio.
     */
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri9']);
    TenantContext::set($conta->id);

    // Por fora da aplicacao, como uma limpeza de banco faria.
    Illuminate\Support\Facades\DB::table('teams')->where('tenant_id', $conta->id)->delete();

    [$canal, $contato] = canalEContato('5511444@s.whatsapp.net');

    $conversa = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    expect($conversa->team_id)->toBeNull();
});

it('o atendente da equipe padrao ve a fila de entrada', function () {
    // O teste que liga as duas pontas: antes da equipe padrao, este cenario dava lista vazia.
    $conta = Tenant::create(['nome' => 'Conta', 'slug' => 'tri10']);
    TenantContext::set($conta->id);

    $ana = User::create([
        'tenant_id' => $conta->id, 'name' => 'Ana', 'email' => 'ana@tri10.test',
        'password' => 'segredo123',
    ]);

    $ana->teams()->attach(Team::padraoDe($conta->id)->id);

    [$canal, $contato] = canalEContato('5511333@s.whatsapp.net');

    $chegou = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    test()->actingAs($ana);

    expect(Conversation::pluck('id')->all())->toBe([$chegou->id])
        ->and($ana->podeVer($chegou->fresh()))->toBeTrue();
});
