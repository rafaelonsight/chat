<?php

use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

function cenarioBalde(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $eu = User::create(['tenant_id' => $t->id, 'name' => 'Eu', 'email' => "eu@{$slug}.test", 'password' => 'segredo123']);
    $outro = User::create(['tenant_id' => $t->id, 'name' => 'Colega', 'email' => "ot@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    return [$t, $eu, $outro, $c];
}

function conv(Channel $c, string $jid, array $atributos = [], string $tipo = Contact::PESSOA, ?string $nome = null): Conversation
{
    $ct = Contact::firstOrCreate(
        ['jid' => $jid],
        [
            'tipo'          => $tipo,
            'nome'          => $nome,
            'telefone_e164' => $tipo === Contact::PESSOA ? '+'.explode('@', $jid)[0] : null,
        ],
    );

    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now()]);

    if ($atributos !== []) {
        $cv->forceFill($atributos)->save();
    }

    return $cv->refresh();
}

function lista(User $u, string $balde = 'novos')
{
    return Livewire::actingAs($u)->test(ConversationList::class)->set('balde', $balde);
}

function ids($conversas): array
{
    return $conversas->pluck('id')->sort()->values()->all();
}

afterEach(fn () => TenantContext::forget());

it('o balde padrao e Novos', function () {
    [, $eu] = cenarioBalde('bd0');

    Livewire::actingAs($eu)->test(ConversationList::class)->assertSet('balde', 'novos');
});

it('Novos traz so quem ninguem pegou, e sem grupo', function () {
    [, $eu, $outro, $c] = cenarioBalde('bd1');

    $nova = conv($c, '5584911111111@s.whatsapp.net');
    conv($c, '5584922222222@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id]);
    conv($c, '5584933333333@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $outro->id]);
    conv($c, '120363011111111111@g.us', [], Contact::GRUPO);
    TenantContext::forget();

    lista($eu, 'novos')->assertViewHas('conversas', fn ($cs) => ids($cs) === [$nova->id]);
});

it('Meus traz so as minhas em atendimento', function () {
    [, $eu, $outro, $c] = cenarioBalde('bd2');

    $minha = conv($c, '5584911111111@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id]);
    conv($c, '5584922222222@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $outro->id]);
    conv($c, '5584933333333@s.whatsapp.net');
    TenantContext::forget();

    lista($eu, 'meus')->assertViewHas('conversas', fn ($cs) => ids($cs) === [$minha->id]);
});

// A clausula "ou atendente nulo" e o que fecha o furo: conversa conduzida por
// automacao tem status em atendimento sem humano e desapareceria da tela.
it('Outros traz as de colega E as sem atendente em atendimento', function () {
    [, $eu, $outro, $c] = cenarioBalde('bd3');

    $doColega = conv($c, '5584911111111@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $outro->id]);
    $daAutomacao = conv($c, '5584922222222@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => null]);
    conv($c, '5584933333333@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id]);
    TenantContext::forget();

    lista($eu, 'outros')->assertViewHas('conversas',
        fn ($cs) => ids($cs) === collect([$doColega->id, $daAutomacao->id])->sort()->values()->all());
});

it('Grupos traz so grupo, e nao os arquivados', function () {
    [, $eu, , $c] = cenarioBalde('bd4');

    $grupo = conv($c, '120363011111111111@g.us', [], Contact::GRUPO);
    conv($c, '120363022222222222@g.us', ['status' => Conversation::ARQUIVADA], Contact::GRUPO);
    conv($c, '5584911111111@s.whatsapp.net');
    TenantContext::forget();

    lista($eu, 'grupos')->assertViewHas('conversas', fn ($cs) => ids($cs) === [$grupo->id]);
});

it('Arquivadas traz tudo que foi encerrado, inclusive grupo', function () {
    [, $eu, , $c] = cenarioBalde('bd5');

    $p = conv($c, '5584911111111@s.whatsapp.net', ['status' => Conversation::ARQUIVADA]);
    $g = conv($c, '120363011111111111@g.us', ['status' => Conversation::ARQUIVADA], Contact::GRUPO);
    conv($c, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    lista($eu, 'arquivadas')->assertViewHas('conversas',
        fn ($cs) => ids($cs) === collect([$p->id, $g->id])->sort()->values()->all());
});

// O teste que importa mais: se a particao deixar algo fora, alguem perde
// atendimento sem nunca saber.
it('a particao cobre TODA conversa: nada fica invisivel', function () {
    [, $eu, $outro, $c] = cenarioBalde('bd6');

    conv($c, '5584911111111@s.whatsapp.net');                                                                  // nova
    conv($c, '5584922222222@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id]);
    conv($c, '5584933333333@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $outro->id]);
    conv($c, '5584944444444@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => null]); // automacao
    conv($c, '5584955555555@s.whatsapp.net', ['status' => Conversation::ARQUIVADA]);
    conv($c, '120363011111111111@g.us', [], Contact::GRUPO);
    conv($c, '120363022222222222@g.us', ['status' => Conversation::ARQUIVADA], Contact::GRUPO);
    TenantContext::forget();

    $vistos = [];
    foreach (array_keys(ConversationList::BALDES) as $balde) {
        lista($eu, $balde)->assertViewHas('conversas', function ($cs) use (&$vistos) {
            $vistos = array_merge($vistos, $cs->pluck('id')->all());

            return true;
        });
    }

    $this->actingAs($eu);
    $todas = Conversation::pluck('id')->sort()->values()->all();

    expect(collect($vistos)->unique()->sort()->values()->all())->toBe($todas)
        ->and(count($vistos))->toBe(count($todas)); // sem sobreposicao
});

it('o badge de Novos conta tudo; o de Meus e Outros conta so nao lidas', function () {
    [, $eu, $outro, $c] = cenarioBalde('bd7');

    conv($c, '5584911111111@s.whatsapp.net', ['nao_lidas' => 0]);
    conv($c, '5584922222222@s.whatsapp.net', ['nao_lidas' => 3]);

    conv($c, '5584933333333@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id, 'nao_lidas' => 0]);
    conv($c, '5584944444444@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id, 'nao_lidas' => 2]);

    conv($c, '5584955555555@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $outro->id, 'nao_lidas' => 1]);
    TenantContext::forget();

    lista($eu)->assertViewHas('badges', fn ($b) => $b['novos'] === 2
        && $b['meus'] === 1
        && $b['outros'] === 1
        && $b['arquivadas'] === null);
});

// Fila se atende por ordem de chegada. Sem isto quem espera mais afunda.
it('Novos ordena do mais antigo para o mais novo', function () {
    [, $eu, , $c] = cenarioBalde('bd8');

    $antiga = conv($c, '5584911111111@s.whatsapp.net', ['ultima_msg_em' => now()->subHours(3)]);
    $recente = conv($c, '5584922222222@s.whatsapp.net', ['ultima_msg_em' => now()->subMinutes(2)]);
    TenantContext::forget();

    lista($eu, 'novos')->assertViewHas('conversas',
        fn ($cs) => $cs->first()->id === $antiga->id && $cs->last()->id === $recente->id);
});

it('os outros baldes ordenam do mais recente para o mais antigo', function () {
    [, $eu, , $c] = cenarioBalde('bd9');

    $antiga = conv($c, '5584911111111@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id, 'ultima_msg_em' => now()->subHours(3)]);
    $recente = conv($c, '5584922222222@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id, 'ultima_msg_em' => now()->subMinutes(2)]);
    TenantContext::forget();

    lista($eu, 'meus')->assertViewHas('conversas',
        fn ($cs) => $cs->first()->id === $recente->id && $cs->last()->id === $antiga->id);
});

it('Apenas nao lidas recorta o balde', function () {
    [, $eu, , $c] = cenarioBalde('bda');

    conv($c, '5584911111111@s.whatsapp.net', ['nao_lidas' => 0]);
    $comNova = conv($c, '5584922222222@s.whatsapp.net', ['nao_lidas' => 4]);
    TenantContext::forget();

    lista($eu, 'novos')
        ->set('somenteNaoLidas', true)
        ->assertViewHas('conversas', fn ($cs) => ids($cs) === [$comNova->id]);
});

it('busca por nome, telefone e conteudo da mensagem', function () {
    [, $eu, , $c] = cenarioBalde('bdb');

    $joao = conv($c, '5584911111111@s.whatsapp.net', [], Contact::PESSOA, 'Joao da Silva');
    $maria = conv($c, '5584999998888@s.whatsapp.net', [], Contact::PESSOA, 'Maria');
    Message::create([
        'conversation_id' => $maria->id, 'channel_id' => $c->id, 'direcao' => 'in',
        'tipo' => 'text', 'corpo' => 'meu modem piscando vermelho', 'status' => Message::STATUS_DELIVERED,
    ]);
    TenantContext::forget();

    lista($eu, 'novos')->set('busca', 'joao')->assertViewHas('conversas', fn ($cs) => ids($cs) === [$joao->id]);
    lista($eu, 'novos')->set('busca', '99998888')->assertViewHas('conversas', fn ($cs) => ids($cs) === [$maria->id]);
    lista($eu, 'novos')->set('busca', 'modem')->assertViewHas('conversas', fn ($cs) => ids($cs) === [$maria->id]);
    lista($eu, 'novos')->set('busca', 'nada disso')->assertViewHas('conversas', fn ($cs) => $cs->isEmpty());
});

it('balde invalido nao muda nada', function () {
    [, $eu] = cenarioBalde('bdc');

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->call('selecionarBalde', 'inexistente')
        ->assertSet('balde', 'novos');
});

it('nada vaza de outro tenant', function () {
    [, , , $cA] = cenarioBalde('bdd');
    conv($cA, '5584911111111@s.whatsapp.net');
    TenantContext::forget();

    [, $euB, , $cB] = cenarioBalde('bde');
    conv($cB, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    lista($euB, 'novos')
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 1)
        ->assertViewHas('badges', fn ($b) => $b['novos'] === 1);
});

// ------------------------------------------------------------- ordenacao

it('o padrao de ordem depende do balde', function () {
    [, $eu] = cenarioBalde('bo1');

    $c = Livewire::actingAs($eu)->test(ConversationList::class);

    $c->assertViewHas('ordemEfetiva', 'antigos');                    // Novos: fila
    $c->set('balde', 'meus')->assertViewHas('ordemEfetiva', 'recentes');
    $c->set('balde', 'arquivadas')->assertViewHas('ordemEfetiva', 'recentes');
});

it('a escolha do menu sobrepoe o padrao e continua valendo ao trocar de balde', function () {
    [, $eu] = cenarioBalde('bo2');

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->call('selecionarOrdem', 'recentes')
        ->assertSet('ordem', 'recentes')
        ->assertViewHas('ordemEfetiva', 'recentes')
        ->set('balde', 'meus')
        ->assertViewHas('ordemEfetiva', 'recentes');
});

it('ordem invalida e ignorada', function () {
    [, $eu] = cenarioBalde('bo3');

    Livewire::actingAs($eu)
        ->test(ConversationList::class)
        ->call('selecionarOrdem', 'aleatorio')
        ->assertSet('ordem', null);
});

it('escolher Mais antigos primeiro reordena um balde que era recente', function () {
    [, $eu, , $c] = cenarioBalde('bo4');

    $antiga = conv($c, '5584911111111@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id, 'ultima_msg_em' => now()->subHours(5)]);
    $recente = conv($c, '5584922222222@s.whatsapp.net', ['status' => Conversation::EM_ATENDIMENTO, 'atendente_id' => $eu->id, 'ultima_msg_em' => now()->subMinutes(1)]);
    TenantContext::forget();

    $c1 = lista($eu, 'meus');
    $c1->assertViewHas('conversas', fn ($cs) => $cs->first()->id === $recente->id);

    $c1->call('selecionarOrdem', 'antigos')
        ->assertViewHas('conversas', fn ($cs) => $cs->first()->id === $antiga->id);
});

it('escolher Ultimas interacoes primeiro reordena a fila de Novos', function () {
    [, $eu, , $c] = cenarioBalde('bo5');

    $antiga = conv($c, '5584911111111@s.whatsapp.net', ['ultima_msg_em' => now()->subHours(5)]);
    $recente = conv($c, '5584922222222@s.whatsapp.net', ['ultima_msg_em' => now()->subMinutes(1)]);
    TenantContext::forget();

    lista($eu, 'novos')
        ->assertViewHas('conversas', fn ($cs) => $cs->first()->id === $antiga->id)
        ->call('selecionarOrdem', 'recentes')
        ->assertViewHas('conversas', fn ($cs) => $cs->first()->id === $recente->id);
});
