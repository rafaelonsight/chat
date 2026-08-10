<?php

use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Team, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * QUAIS CANAIS E TIMES CADA PESSOA PODE VER.
 *
 * A REGRA E RESTRICAO DE VERDADE, e nao filtro de conveniencia: o pedido do Rafael foi "quais
 * canais e times ele PODERA TER ACESSO". Seletor que nao corta consulta e teatro — a conversa
 * continuaria aparecendo, e bastaria um link direto para chegar nela.
 *
 * AS DUAS REGRAS NAO SAO SIMETRICAS, e isso e proposital:
 *
 *   canal sem vinculo -> ve todos (senao subir esta mudanca trancaria todo mundo para fora,
 *                        porque ninguem tinha canal vinculado)
 *   time  com vinculo -> ve SO os times dele, e NAO ve as conversas sem time. Decisao do
 *                        Rafael: a triagem e do chatbot ou de quem esta no time Triagem.
 */

function cenarioAcesso(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);

    $chefe = User::create([
        'tenant_id' => $t->id, 'name' => 'Chefe', 'email' => "ch@{$slug}.test",
        'password' => 'segredo123', 'admin' => true,
    ]);

    $ana = User::create([
        'tenant_id' => $t->id, 'name' => 'Ana', 'email' => "an@{$slug}.test",
        'password' => 'segredo123',
    ]);

    $vendas = Channel::create(['nome' => 'Vendas']);
    $suporte = Channel::create(['nome' => 'Suporte']);

    return [$t, $chefe, $ana, $vendas->refresh(), $suporte->refresh()];
}

function convAc(Channel $canal, string $jid, ?int $timeId = null): Conversation
{
    $contato = Contact::firstOrCreate(
        ['jid' => $jid],
        ['tipo' => Contact::PESSOA, 'telefone_e164' => '+'.explode('@', $jid)[0]],
    );

    $c = Conversation::create([
        'channel_id' => $canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    if ($timeId) {
        $c->forceFill(['team_id' => $timeId])->save();
    }

    return $c->refresh();
}

afterEach(fn () => TenantContext::forget());

// ------------------------------------------------------------------ o padrao

it('sem vinculo nenhum, ve todos os canais', function () {
    /*
     * O teste que protege a subida: no dia em que isto foi para o ar, NENHUM usuario tinha
     * canal vinculado. Se vazio significasse "nada", a tela de todos os atendentes teria
     * apagado de uma vez.
     */
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac1');

    convAc($vendas, '551199@s.whatsapp.net');
    convAc($suporte, '551188@s.whatsapp.net');

    $this->actingAs($ana);

    expect(Conversation::count())->toBe(2)
        ->and(Channel::count())->toBe(2)
        ->and($ana->temAcessoRestrito())->toBeFalse();
});

// ------------------------------------------------------------------ por canal

it('com canal vinculado, ve so as conversas daquele canal', function () {
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac2');

    $daVenda = convAc($vendas, '551199@s.whatsapp.net');
    convAc($suporte, '551188@s.whatsapp.net');

    $ana->canais()->attach($vendas->id);

    $this->actingAs($ana);

    $vistas = Conversation::pluck('id')->all();

    expect($vistas)->toBe([$daVenda->id]);
});

it('o canal que nao e dele nem aparece na lista de canais', function () {
    // Efeito colateral do escopo estar no proprio Channel: o filtro do atendimento e o
    // "nova conversa" ficaram restritos sem uma linha a mais em cada um.
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac3');

    $ana->canais()->attach($vendas->id);

    $this->actingAs($ana);

    expect(Channel::pluck('nome')->all())->toBe(['Vendas']);
});

it('link direto para conversa de outro canal nao abre', function () {
    /*
     * O CENTRO DA COISA. Esconder da lista e metade do trabalho: se a consulta por id ainda
     * achar, basta um id na URL — ou um atalho de teclado — para furar a regra. O escopo global
     * fecha o caminho direto junto com a lista, porque e o MESMO caminho.
     */
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac4');

    $doOutro = convAc($suporte, '551188@s.whatsapp.net');

    $ana->canais()->attach($vendas->id);

    $this->actingAs($ana);

    expect(Conversation::find($doOutro->id))->toBeNull();
});

// ------------------------------------------------------------------- por time

it('com time vinculado, NAO ve a conversa sem time', function () {
    // Decisao do Rafael, e a razao existe: a triagem e feita pelo chatbot ou por quem esta no
    // time Triagem. A fila de entrada nao e de todo mundo.
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac5');

    $financeiro = Team::create(['nome' => 'Financeiro']);

    $comTime = convAc($vendas, '551199@s.whatsapp.net', $financeiro->id);
    convAc($vendas, '551188@s.whatsapp.net');

    $ana->teams()->attach($financeiro->id);

    $this->actingAs($ana);

    expect(Conversation::pluck('id')->all())->toBe([$comTime->id]);
});

it('nao ve a conversa de um time que nao e dele', function () {
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac6');

    $meu = Team::create(['nome' => 'Financeiro']);
    $outro = Team::create(['nome' => 'Suporte']);

    $minha = convAc($vendas, '551199@s.whatsapp.net', $meu->id);
    convAc($vendas, '551177@s.whatsapp.net', $outro->id);

    $ana->teams()->attach($meu->id);

    $this->actingAs($ana);

    expect(Conversation::pluck('id')->all())->toBe([$minha->id]);
});

it('canal e time se somam, nao se substituem', function () {
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac7');

    $time = Team::create(['nome' => 'Financeiro']);

    $certa = convAc($vendas, '551199@s.whatsapp.net', $time->id);
    convAc($suporte, '551188@s.whatsapp.net', $time->id);   // time certo, canal errado
    convAc($vendas, '551177@s.whatsapp.net');               // canal certo, sem time

    $ana->canais()->attach($vendas->id);
    $ana->teams()->attach($time->id);

    $this->actingAs($ana);

    expect(Conversation::pluck('id')->all())->toBe([$certa->id])
        ->and($ana->temAcessoRestrito())->toBeTrue();
});

// ---------------------------------------------------------------- quem ve tudo

it('administrador passa por cima de qualquer restricao', function () {
    // Quem configura canais e usuarios precisa ver o sistema inteiro: restringir seria pedir
    // que ele configure no escuro.
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac8');

    $time = Team::create(['nome' => 'Financeiro']);

    convAc($vendas, '551199@s.whatsapp.net', $time->id);
    convAc($suporte, '551188@s.whatsapp.net');

    $chefe->canais()->attach($vendas->id);
    $chefe->teams()->attach($time->id);

    $this->actingAs($chefe);

    expect(Conversation::count())->toBe(2)
        ->and(Channel::count())->toBe(2)
        ->and($chefe->veTudo())->toBeTrue()
        ->and($chefe->temAcessoRestrito())->toBeFalse();
});

it('sem ninguem logado nao ha restricao, senao a fila para', function () {
    /*
     * Job, webhook e console agem em nome do sistema, nao de uma pessoa. Se o escopo valesse ali
     * tambem, a mensagem que chega da Evolution nao acharia a conversa e a ENTRADA DE MENSAGEM
     * inteira pararia — um jeito espetacular de quebrar o produto tentando proteger uma tela.
     */
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac9');

    convAc($vendas, '551199@s.whatsapp.net');
    convAc($suporte, '551188@s.whatsapp.net');

    // Ninguem autenticado: e exatamente o cenario de fila.
    expect(auth()->user())->toBeNull()
        ->and(Conversation::count())->toBe(2);
});

// ------------------------------------------------------------------ na tela

it('a lista do atendimento mostra so o que e dele', function () {
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac10');

    convAc($vendas, '551199@s.whatsapp.net');
    convAc($suporte, '551188@s.whatsapp.net');

    $ana->canais()->attach($vendas->id);

    $this->actingAs($ana);

    Livewire::test(ConversationList::class)
        ->assertSee('+551199')
        ->assertDontSee('+551188');
});

it('a lista oferece so os times que ele pode abrir', function () {
    // Escolha que nao leva a nada e a pior forma de dizer "voce nao pode".
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac11');

    $meu = Team::create(['nome' => 'Financeiro']);
    Team::create(['nome' => 'Suporte tecnico']);

    $ana->teams()->attach($meu->id);

    $this->actingAs($ana);

    Livewire::test(ConversationList::class)
        ->assertSee('Financeiro')
        ->assertDontSee('Suporte tecnico');
});

it('pedir os canais do usuario nao entra em recursao', function () {
    /*
     * O Channel carrega o escopo de acesso, e o escopo pergunta ao usuario quais canais ele
     * pode ver — que e a propria consulta. Sem o withoutGlobalScope no canalIds(), a pergunta se
     * responde chamando a si mesma e a requisicao morre. Este teste existe para que ninguem
     * "limpe" aquele withoutGlobalScope achando que era enfeite.
     */
    [$t, $chefe, $ana, $vendas, $suporte] = cenarioAcesso('ac12');

    $ana->canais()->attach($vendas->id);

    $this->actingAs($ana);

    expect($ana->canalIds())->toBe([$vendas->id]);
});
