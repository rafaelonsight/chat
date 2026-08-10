<?php

use App\Filament\Resources\Teams\TeamResource;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, ConversationEvent, Team, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

function cenarioEquipe(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $admin = User::create(['tenant_id' => $t->id, 'name' => 'Admin', 'email' => "ad@{$slug}.test", 'password' => 'segredo123', 'admin' => true]);
    $ana = User::create(['tenant_id' => $t->id, 'name' => 'Ana', 'email' => "an@{$slug}.test", 'password' => 'segredo123']);
    $bruno = User::create(['tenant_id' => $t->id, 'name' => 'Bruno', 'email' => "br@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();

    return [$t, $admin, $ana, $bruno, $c];
}

function convEq(Channel $c, string $jid, ?int $teamId = null, array $extra = []): Conversation
{
    $ct = Contact::firstOrCreate(
        ['jid' => $jid],
        ['tipo' => Contact::PESSOA, 'telefone_e164' => '+'.explode('@', $jid)[0]],
    );

    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now()]);

    if ($teamId || $extra !== []) {
        $cv->forceFill(array_merge($teamId ? ['team_id' => $teamId] : [], $extra))->save();
    }

    return $cv->refresh();
}

afterEach(fn () => TenantContext::forget());

// -------------------------------------------------------------- modelo

it('cria equipe e mostra quem faz parte', function () {
    [, , $ana, $bruno] = cenarioEquipe('eq1');

    $suporte = Team::create(['nome' => 'Suporte', 'descricao' => 'Rede e conexão']);
    $suporte->users()->attach([$ana->id => ['papel' => 'atendente'], $bruno->id => ['papel' => 'supervisor']]);

    expect($suporte->users)->toHaveCount(2)
        ->and($suporte->users->pluck('name')->sort()->values()->all())->toBe(['Ana', 'Bruno'])
        ->and($suporte->users->firstWhere('name', 'Bruno')->pivot->papel)->toBe('supervisor');
});

it('pessoa pode estar em varias equipes', function () {
    [, , $ana] = cenarioEquipe('eq2');

    $suporte = Team::create(['nome' => 'Suporte']);
    $financeiro = Team::create(['nome' => 'Financeiro']);
    $ana->teams()->attach([$suporte->id, $financeiro->id]);

    expect($ana->refresh()->teams)->toHaveCount(2)
        ->and($ana->equipeIds())->toContain($suporte->id, $financeiro->id);
});

it('nome de equipe nao repete no mesmo tenant', function () {
    cenarioEquipe('eq3');
    Team::create(['nome' => 'Suporte']);

    expect(fn () => Team::create(['nome' => 'Suporte']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('equipe nao vaza entre tenants', function () {
    cenarioEquipe('eq4');
    Team::create(['nome' => 'Suporte']);
    TenantContext::forget();

    [, $adminB] = cenarioEquipe('eq5');
    $this->actingAs($adminB);

    expect(Team::count())->toBe(0);
});

// ------------------------------------------------------------ transferir

it('transferir devolve a conversa para Novos da equipe destino', function () {
    [, , $ana, , $c] = cenarioEquipe('eq6');
    $suporte = Team::create(['nome' => 'Suporte']);
    $financeiro = Team::create(['nome' => 'Financeiro']);

    $cv = convEq($c, '5584911111111@s.whatsapp.net', $suporte->id, [
        'status'       => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $ana->id,
    ]);

    $this->actingAs($ana);
    $cv->transferir($financeiro, $ana);

    $cv->refresh();
    expect($cv->team_id)->toBe($financeiro->id)
        ->and($cv->status)->toBe(Conversation::NOVA)
        ->and($cv->atendente_id)->toBeNull();
});

// Nao temos como registrar nada que nao seja enviado ao cliente: toda mensagem
// nossa vai para o WhatsApp. Evento resolve isso — e vira a auditoria.
it('transferencia deixa rastro que NAO vai para o cliente', function () {
    [, , $ana, , $c] = cenarioEquipe('eq7');
    $suporte = Team::create(['nome' => 'Suporte']);
    $financeiro = Team::create(['nome' => 'Financeiro']);
    $cv = convEq($c, '5584911111111@s.whatsapp.net', $suporte->id);

    $this->actingAs($ana);
    $cv->transferir($financeiro, $ana);

    $evento = ConversationEvent::where('conversation_id', $cv->id)->first();
    expect($evento)->not->toBeNull()
        ->and($evento->tipo)->toBe('transferencia')
        ->and($evento->descricao)->toContain('Financeiro')
        ->and($evento->user_id)->toBe($ana->id);

    // e nenhuma mensagem foi criada, ou seja: o cliente nao recebeu nada
    expect($cv->messages()->count())->toBe(0);
});

it('a janela transfere pela tela', function () {
    [, , $ana, , $c] = cenarioEquipe('eq8');
    $financeiro = Team::create(['nome' => 'Financeiro']);
    $cv = convEq($c, '5584911111111@s.whatsapp.net');

    Livewire::actingAs($ana)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('transferir', $financeiro->id)
        ->assertDispatched('conversa-atualizada');

    expect($cv->refresh()->team_id)->toBe($financeiro->id);
});

it('nao transfere para equipe de outro tenant', function () {
    cenarioEquipe('eq9');
    $doOutro = Team::create(['nome' => 'Alheia']);
    TenantContext::forget();

    [, , $ana, , $c] = cenarioEquipe('eqa');
    $cv = convEq($c, '5584911111111@s.whatsapp.net');
    TenantContext::forget();

    Livewire::actingAs($ana)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('transferir', $doOutro->id);

    expect($cv->refresh()->team_id)->toBeNull();
});

// ------------------------------------------------------------- inbox

// Propriedade que nao pode quebrar: sem equipe cadastrada, tudo funciona como
// antes. Ninguem fica com inbox vazio por nao pertencer a equipe nenhuma.
it('com zero equipes o inbox se comporta como antes', function () {
    [, , $ana, , $c] = cenarioEquipe('eqb');
    convEq($c, '5584911111111@s.whatsapp.net');
    convEq($c, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    Livewire::actingAs($ana)
        ->test(ConversationList::class)
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 2);
});

it('quem nao esta em equipe nenhuma continua vendo tudo', function () {
    [, , $ana, , $c] = cenarioEquipe('eqc');
    $suporte = Team::create(['nome' => 'Suporte']);
    convEq($c, '5584911111111@s.whatsapp.net', $suporte->id);
    convEq($c, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    // Ana nao pertence a nenhuma equipe
    Livewire::actingAs($ana)
        ->test(ConversationList::class)
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 2);
});

it('quem esta num time NAO ve as conversas sem time', function () {
    /*
     * A REGRA MUDOU AQUI, e por decisao do Rafael — nao por acidente.
     *
     * Antes este teste se chamava "minhas equipes traz as minhas MAIS as sem equipe": estar num
     * time era um FILTRO de conveniencia, e a fila de entrada (sem time) era de todos. Quando o
     * acesso por canal e time entrou, ele perguntou o que fazer com essa fila e escolheu o lado
     * fechado: a triagem e feita pelo chatbot ou por quem esta no time Triagem, entao a entrada
     * nao e de todo mundo.
     *
     * Se este teste voltar a passar com a conversa sem time na lista, a regra de acesso foi
     * afrouxada sem ninguem decidir isso.
     */
    [, , $ana, , $c] = cenarioEquipe('eqd');
    $suporte = Team::create(['nome' => 'Suporte']);
    $financeiro = Team::create(['nome' => 'Financeiro']);
    $ana->teams()->attach($suporte->id);

    $minha = convEq($c, '5584911111111@s.whatsapp.net', $suporte->id);
    convEq($c, '5584922222222@s.whatsapp.net');
    convEq($c, '5584933333333@s.whatsapp.net', $financeiro->id);
    TenantContext::forget();

    Livewire::actingAs($ana)
        ->test(ConversationList::class)
        ->assertSet('equipe', 'minhas')
        ->assertViewHas('conversas', fn ($cs) => $cs->pluck('id')->all() === [$minha->id]);
});

it('quem nao esta em time nenhum continua vendo tudo', function () {
    // O outro lado da regra, e o que impediu esta mudanca de trancar todo mundo para fora:
    // sem vinculo de time, nada e restringido — inclusive a fila sem time.
    [, , $ana, , $c] = cenarioEquipe('eqd2');
    $suporte = Team::create(['nome' => 'Suporte']);

    convEq($c, '5584911111111@s.whatsapp.net', $suporte->id);
    convEq($c, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    Livewire::actingAs($ana)
        ->test(ConversationList::class)
        ->set('equipe', 'todas')
        ->assertViewHas('conversas', fn ($cs) => $cs->count() === 2);
});

it('para quem e restrito, "todas" quer dizer todos os times DELE', function () {
    /*
     * "Todas" e um recorte da tela, nao uma chave de permissao — e essa distincao e o ponto.
     * Antes ele mostrava as tres conversas; agora mostra so a do time da Ana, porque o acesso
     * corta a consulta antes de qualquer filtro de tela chegar nela. E o filtro por um time que
     * nao e dela devolve VAZIO em vez de devolver a conversa do outro time.
     */
    [, , $ana, , $c] = cenarioEquipe('eqe');
    $suporte = Team::create(['nome' => 'Suporte']);
    $financeiro = Team::create(['nome' => 'Financeiro']);
    $ana->teams()->attach($suporte->id);

    $minha = convEq($c, '5584911111111@s.whatsapp.net', $suporte->id);
    convEq($c, '5584933333333@s.whatsapp.net', $financeiro->id);
    convEq($c, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    $lista = fn (string $eq) => Livewire::actingAs($ana)->test(ConversationList::class)->set('equipe', $eq);

    $lista('todas')->assertViewHas('conversas', fn ($cs) => $cs->pluck('id')->all() === [$minha->id]);
    $lista((string) $suporte->id)->assertViewHas('conversas', fn ($cs) => $cs->pluck('id')->all() === [$minha->id]);
    $lista((string) $financeiro->id)->assertViewHas('conversas', fn ($cs) => $cs->count() === 0);
});

it('sem equipe filtra so as nao roteadas', function () {
    [, , $ana, , $c] = cenarioEquipe('eqf');
    $suporte = Team::create(['nome' => 'Suporte']);
    convEq($c, '5584911111111@s.whatsapp.net', $suporte->id);
    $solta = convEq($c, '5584922222222@s.whatsapp.net');
    TenantContext::forget();

    Livewire::actingAs($ana)
        ->test(ConversationList::class)
        ->set('equipe', 'sem')
        ->assertViewHas('conversas', fn ($cs) => $cs->pluck('id')->all() === [$solta->id]);
});

it('o seletor lista so as equipes ativas do tenant', function () {
    [, , $ana] = cenarioEquipe('eqg');
    Team::create(['nome' => 'Suporte']);
    Team::create(['nome' => 'Desativada', 'ativa' => false]);
    TenantContext::forget();

    Livewire::actingAs($ana)
        ->test(ConversationList::class)
        ->assertViewHas('equipes', fn ($e) => $e->count() === 1 && $e->first()->nome === 'Suporte');
});

// ------------------------------------------------------------- painel

it('Equipe deixou de ser reservada e so admin acessa', function () {
    [, $admin, $ana] = cenarioEquipe('eqh');

    $this->actingAs($admin);
    expect(TeamResource::canViewAny())->toBeTrue();

    auth()->logout();
    $this->actingAs($ana);
    expect(TeamResource::canViewAny())->toBeFalse();
});

it('a lista de equipes abre e mostra a contagem de gente', function () {
    [, $admin, $ana, $bruno] = cenarioEquipe('eqi');
    $suporte = Team::create(['nome' => 'Suporte']);
    $suporte->users()->attach([$ana->id, $bruno->id]);
    TenantContext::forget();

    $this->withoutExceptionHandling();
    $this->withSession(['login_web_'.sha1('Illuminate\Auth\SessionGuard') => $admin->id])
        ->get('/admin/teams')
        ->assertSuccessful()
        ->assertSee('Suporte');
});
