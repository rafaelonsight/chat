<?php

use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * Atalhos de teclado.
 *
 * ATE ONDE ESTE ARQUIVO VAI, E ONDE PARA. A tecla em si e o navegador: teste de PHP nao aperta
 * "j". O que da para garantir aqui e o que acontece DEPOIS da tecla — a navegacao pela lista na
 * ordem certa, e os atalhos chamando os mesmos metodos dos botoes.
 *
 * O que ele nao prova: que a tecla dispara, que nao dispara enquanto se escreve, e que a lista
 * de ajuda abre no "?". Isso e o Rafael com o teclado.
 *
 * A ORDEM E CALCULADA NO SERVIDOR de proposito: so ele sabe qual e a proxima depois de balde,
 * equipe, etiqueta, canal e a regra das fixadas. Contar linhas no navegador daria certo ate o
 * primeiro filtro — e falharia em silencio, pulando conversa.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'atalhos']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@atalhos.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'ata',
    ]);

    // Tres conversas, da mais antiga para a mais nova.
    $this->conversas = collect(range(1, 3))->map(function ($i) {
        $contato = Contact::create([
            'tenant_id' => $this->conta->id, 'nome' => 'Cliente '.$i,
            'telefone_e164' => '+55419999900'.$i, 'jid' => '55419999900'.$i.'@s.whatsapp.net',
        ]);

        $c = Conversation::create([
            'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
            'contact_id' => $contato->id, 'status' => Conversation::EM_ATENDIMENTO,
            'atendente_id' => $this->pessoa->id,
            'ultima_msg_em' => now()->subMinutes(10 - $i), 'ultima_entrada_em' => now(),
        ]);

        Message::create([
            'tenant_id' => $this->conta->id, 'conversation_id' => $c->id,
            'channel_id' => $this->canal->id, 'direcao' => 'in', 'tipo' => 'text',
            'corpo' => 'oi '.$i, 'status' => Message::STATUS_DELIVERED,
        ]);

        return $c;
    });

    $this->actingAs($this->pessoa);
});

/*
 * 'todas' e nao 'sem'.
 *
 * A intencao aqui sempre foi "nao filtre por equipe" — e antes 'sem' servia para isso por
 * acidente, porque nenhuma conversa tinha equipe. Quando a Triagem virou padrao em toda licenca,
 * 'sem equipe' passou a devolver lista vazia, corretamente: nao existe mais conversa sem dono de
 * fila. Quem quer a lista inteira pede 'todas', que e o que ela sempre quis dizer.
 */
function listaAberta($pessoa)
{
    return Livewire::actingAs($pessoa)->test(ConversationList::class)
        ->set('equipe', 'todas')->set('balde', 'meus');
}

// ----------------------------------------------------------- navegar (j / k)

it('sem nada selecionado, j entra na primeira da lista', function () {
    // E o que a pessoa quer ao apertar j numa lista parada.
    $tela = listaAberta($this->pessoa)->call('irParaVizinha', 1);

    $primeira = $tela->viewData('conversas')->first();

    expect($tela->get('selecionada'))->toBe($primeira->id);
});

it('j desce e k sobe, na ordem que esta na tela', function () {
    $tela = listaAberta($this->pessoa);
    $ordem = $tela->viewData('conversas')->pluck('id')->all();

    $tela->call('irParaVizinha', 1);
    expect($tela->get('selecionada'))->toBe($ordem[0]);

    $tela->call('irParaVizinha', 1);
    expect($tela->get('selecionada'))->toBe($ordem[1]);

    $tela->call('irParaVizinha', -1);
    expect($tela->get('selecionada'))->toBe($ordem[0]);
});

it('nao da a volta no fim nem no comeco', function () {
    // Chegar ao fim e voltar ao topo sem aviso faz a pessoa reler o que ja leu achando que
    // sao conversas novas.
    $tela = listaAberta($this->pessoa);
    $ordem = $tela->viewData('conversas')->pluck('id')->all();

    $tela->set('selecionada', end($ordem))->call('irParaVizinha', 1);
    expect($tela->get('selecionada'))->toBe(end($ordem));

    $tela->set('selecionada', $ordem[0])->call('irParaVizinha', -1);
    expect($tela->get('selecionada'))->toBe($ordem[0]);
});

it('navegar abre a conversa, e nao so destaca a linha', function () {
    listaAberta($this->pessoa)->call('irParaVizinha', 1)->assertDispatched('abrir-conversa');
});

it('a navegacao respeita o filtro que esta na tela', function () {
    // Contar linhas no navegador daria certo ate o primeiro filtro. Aqui a busca reduz a
    // lista a uma conversa, e j nao pode saltar para fora dela.
    $tela = listaAberta($this->pessoa)->set('busca', 'Cliente 2');

    $visiveis = $tela->viewData('conversas')->pluck('id')->all();
    expect($visiveis)->toHaveCount(1);

    $tela->call('irParaVizinha', 1);
    expect($tela->get('selecionada'))->toBe($visiveis[0]);

    $tela->call('irParaVizinha', 1);
    expect($tela->get('selecionada'))->toBe($visiveis[0]);
});

it('lista vazia nao quebra', function () {
    $tela = listaAberta($this->pessoa)->set('busca', 'nao existe ninguem assim');

    $tela->call('irParaVizinha', 1);

    expect($tela->get('selecionada'))->toBeNull();
});

// --------------------------------------- os atalhos usam os mesmos metodos

it('o atalho de encerrar chama o MESMO metodo do botao', function () {
    // Um caminho separado para o teclado seria uma segunda regra para manter, e a primeira a
    // ficar para tras.
    $listeners = (new ConversationWindow)->getListeners();

    expect($listeners['atalho-finalizar'])->toBe('finalizar')
        ->and($listeners['atalho-nao-lida'])->toBe('marcarNaoLida');
});

it('encerrar pelo atalho arquiva a conversa', function () {
    $c = $this->conversas->first();

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $c->id])
        ->call('finalizar');

    expect($c->fresh()->status)->toBe(Conversation::ARQUIVADA);
});

it('a lista escuta o atalho de navegar', function () {
    expect((new ConversationList)->getListeners()['atalho-navegar'])->toBe('irParaVizinha');
});
