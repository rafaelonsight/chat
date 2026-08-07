<?php

use App\Livewire\Inbox\ConversationList;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * De qual canal e a conversa.
 *
 * O PROBLEMA ERA CONCRETO, e apareceu na tela do Rafael: duas conversas com o MESMO contato,
 * abertas ao mesmo tempo, identicas na lista. Elas so podem coexistir porque estao em canais
 * diferentes — o banco tem indice unico de uma conversa aberta por contato E CANAL. A
 * informacao existia; ela so nao estava em lugar nenhum que alguem pudesse ver.
 *
 * TRES COISAS, cada uma respondendo uma pergunta diferente:
 *
 *   icone      -> de que PLATAFORMA o cliente escreveu (WhatsApp, Instagram, Messenger)
 *   pontinho   -> QUAL canal, quando ha mais de um na mesma plataforma
 *   title      -> nome do canal E O NUMERO, porque "RP" nao lembra nada a quem entrou ontem
 *
 * O icone sozinho nao resolveria: os tres canais de hoje sao WhatsApp, e sairiam com o mesmo
 * verde. Foi por isso que o pontinho com o nome ficou.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'canal-lista']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@canal.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->rp = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'RP', 'tipo' => 'evolution',
        'status' => 'open', 'instance_name' => 'rp', 'telefone_e164' => '+5541984919939',
    ]);

    $this->pessoal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Pessoal', 'tipo' => 'evolution',
        'status' => 'open', 'instance_name' => 'pe', 'telefone_e164' => '+5541988887777',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Rafael',
        'telefone_e164' => '+5543996386381', 'jid' => '5543996386381@s.whatsapp.net',
    ]);

    // As duas conversas do mesmo contato, uma em cada canal — o caso real.
    foreach ([$this->rp, $this->pessoal] as $i => $canal) {
        $c = Conversation::create([
            'tenant_id' => $this->conta->id, 'channel_id' => $canal->id,
            'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
            'atendente_id' => $this->pessoa->id, 'ultima_msg_em' => now()->subMinutes($i),
        ]);

        Message::create([
            'tenant_id' => $this->conta->id, 'conversation_id' => $c->id,
            'channel_id' => $canal->id, 'direcao' => 'in', 'tipo' => 'text',
            'corpo' => 'oi do '.$canal->nome, 'status' => Message::STATUS_DELIVERED,
        ]);
    }

    $this->actingAs($this->pessoa);
});

/**
 * A lista no recorte que mostra estas conversas.
 *
 * O balde padrao e "Novos" e o filtro de equipe padrao e "minhas". As conversas do cenario
 * estao EM ATENDIMENTO com dono e SEM equipe — ou seja, ficam de fora dos dois padroes. O
 * teste vazio nao era defeito do codigo: era eu olhando para o recorte errado.
 */
function listaDoAtendente($pessoa)
{
    return Livewire::actingAs($pessoa)->test(ConversationList::class)
        ->set('equipe', 'sem')
        ->set('balde', 'meus');
}

// ----------------------------------------------------------------- o modelo

it('o rotulo tem o nome E o numero', function () {
    // O numero e a parte que importa com tres canais na mesma plataforma: "RP" nao lembra
    // nada a quem entrou ontem na equipe.
    expect($this->rp->rotulo())->toContain('RP')
        ->and($this->rp->rotulo())->toContain('41');
});

it('canal sem numero confirmado nao mostra numero vazio', function () {
    $novo = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Recem criado', 'tipo' => 'evolution',
        'status' => 'close', 'instance_name' => 'novo',
    ]);

    expect($novo->rotulo())->toContain('Recem criado')
        ->and($novo->rotulo())->toContain('ainda não confirmado');
});

it('a cor e estavel: o mesmo canal sempre da a mesma cor', function () {
    expect($this->rp->cor())->toBe($this->rp->fresh()->cor())
        ->and($this->rp->cor())->not->toBe($this->pessoal->cor());
});

it('os dois tipos de WhatsApp devolvem a plataforma whatsapp', function () {
    // Tipo diz por onde NOS conectamos; plataforma diz o que o CLIENTE usou. Confundir os
    // dois faria o canal oficial aparecer com outro icone, como se fosse outro aplicativo.
    $oficial = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Oficial', 'tipo' => 'meta_cloud',
        'status' => 'open', 'meta_phone_number_id' => '1', 'meta_waba_id' => '2', 'meta_token' => 't',
    ]);

    expect($this->rp->plataforma())->toBe('whatsapp')
        ->and($oficial->plataforma())->toBe('whatsapp');
});

// ------------------------------------------------------------------ a tela

it('a lista mostra o nome dos dois canais quando ha mais de um', function () {
    listaDoAtendente($this->pessoa)
        ->assertSet('canal', null)
        ->assertSee('RP')
        ->assertSee('Pessoal')
        // e o numero aparece no title, para o mouse. Afirmo contra o proprio rotulo em vez
        // de escrever a formatacao a mao: o formato e decisao do PhoneNumber, e um teste que
        // repete a formatacao passa a testar a minha suposicao, nao o comportamento.
        ->assertSee($this->rp->rotulo());
});

it('com UM canal so, a marca nao aparece: nao separaria nada', function () {
    // Marca que nao distingue nada e enfeite ocupando o lugar de informacao util.
    Conversation::withoutGlobalScope('tenant')->where('channel_id', $this->pessoal->id)->delete();
    $this->pessoal->delete();

    $dados = listaDoAtendente($this->pessoa)->viewData('multiCanal');

    expect($dados)->toBeFalse();
});

it('filtrar por canal deixa so as conversas daquele numero', function () {
    listaDoAtendente($this->pessoa)
        ->set('canal', (string) $this->rp->id)
        ->assertSee('oi do RP')
        ->assertDontSee('oi do Pessoal');
});

it('sem filtro, as duas conversas do mesmo contato aparecem', function () {
    // O caso que motivou tudo: duas linhas iguais na tela do Rafael.
    $conversas = listaDoAtendente($this->pessoa)->viewData('conversas');

    expect($conversas)->toHaveCount(2)
        ->and($conversas->pluck('channel_id')->all())
        ->toContain($this->rp->id, $this->pessoal->id);
});

it('trocar de canal reinicia a paginacao', function () {
    // Sem isto, trocar o recorte mantem o "carregar mais" de antes e a lista mostra um
    // pedaco do meio.
    listaDoAtendente($this->pessoa)
        ->call('carregarMais')
        ->set('canal', (string) $this->rp->id)
        ->assertSet('limite', ConversationList::PAGINA);
});

it('o filtro de canal nao vaza para outra conta', function () {
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-canal']);
    $canalAlheio = Channel::withoutGlobalScope('tenant')->create([
        'tenant_id' => $outra->id, 'nome' => 'De outro', 'tipo' => 'evolution',
        'status' => 'open', 'instance_name' => 'alheio',
    ]);

    $canais = listaDoAtendente($this->pessoa)->viewData('canais');

    expect($canais->pluck('id')->all())->not->toContain($canalAlheio->id);
});
