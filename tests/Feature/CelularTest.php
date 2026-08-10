<?php

use App\Models\{Channel, Contact, Conversation, Tenant, User};
use App\Livewire\Inbox\ConversationWindow;
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * A caixa de entrada no celular.
 *
 * O atendente trabalha do telefone o dia todo. A tela era tres paineis lado a lado com largura
 * fixa e nenhum ponto de quebra: no celular viravam tres colunas espremidas e nenhuma usavel.
 *
 * ATE ONDE ESTE ARQUIVO CONSEGUE IR, E ONDE ELE PARA.
 *
 * Layout se prova OLHANDO, e teste de PHP nao enxerga tela. O que da para garantir aqui e que
 * as pecas que fazem a troca de vista continuam no lugar: o estado, os quatro eventos que o
 * movem, os pontos de quebra e o caminho de volta. Nenhuma delas se ve; todas quebram calado
 * se alguem apagar por engano numa refatoracao — e o sintoma seria "no celular sumiu tudo",
 * dias depois, sem ninguem ligar uma coisa a outra.
 *
 * O que ele NAO prova: que fica bonito, que o dedo alcanca, que o teclado nao cobre o campo de
 * escrever. Isso e o Rafael abrindo no telefone dele.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'celular']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@celular.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'cel',
    ]);

    $contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $contato->id, 'status' => 'aberta',
    ]);

    $this->actingAs($this->pessoa);
});

function inbox(): Illuminate\Testing\TestResponse
{
    // /admin e nao /admin/atendimento: a caixa de entrada E a home do painel. O
    // Atendimento estende o Dashboard do Filament de proposito — num app de atendimento a
    // primeira tela e a conversa, nao um quadro de indicadores.
    return test()->get('/admin');
}

it('a caixa de entrada abre', function () {
    inbox()->assertOk();
});

it('guarda qual vista esta na frente', function () {
    inbox()->assertSee("x-data=\"{ vista: 'lista' }\"", false);
});

it('os quatro eventos que trocam a vista estao ligados', function () {
    // Se qualquer um sumir, o celular trava numa vista so — e no computador ninguem percebe,
    // porque la as tres colunas aparecem juntas de qualquer jeito.
    $html = inbox()->getContent();

    foreach (['abrir-conversa', 'abrir-detalhes', 'voltar-para-lista', 'voltar-para-conversa'] as $evento) {
        expect($html)->toContain('x-on:'.$evento.'.window');
    }
});

it('cada regiao some sozinha quando nao e a vista da vez', function () {
    $html = inbox()->getContent();

    expect($html)->toContain(":class=\"vista === 'lista' ? 'flex' : 'hidden'\"")
        ->and($html)->toContain(":class=\"vista === 'conversa' ? 'flex' : 'hidden'\"")
        ->and($html)->toContain(":class=\"vista === 'detalhes' ? 'contents' : 'hidden'\"");
});

it('no computador as tres colunas voltam, apesar do que o Alpine decidir', function () {
    // O par "hidden + lg:*" e o que garante isso: o Tailwind emite as variantes de breakpoint
    // depois das utilidades base, entao no tamanho grande o lg: vence.
    $html = inbox()->getContent();

    // 384px (w-96) e nao 320 (w-80): em 320 a fileira de seis filtros esmagava os dois
    // seletores ate sobrar so a setinha sem texto, e nome de contato quebrava no meio.
    // O teste continua guardando a mesma coisa — que no computador a lista tem largura
    // FIXA, e nao fluida — so mudou qual e ela.
    expect($html)->toContain('lg:flex lg:w-96')   // a lista volta a ter largura fixa
        ->and($html)->toContain('lg:contents');   // e os detalhes voltam a ser coluna propria
});

it('a altura usa dvh no celular', function () {
    // vh no celular mede a tela SEM descontar a barra de endereco, que aparece e some. O campo
    // de escrever ficava escondido atras dela justamente enquanto se digita.
    inbox()->assertSee('h-[calc(100dvh-', false);
});

it('existe caminho de volta da conversa para a lista', function () {
    // No componente da JANELA e nao na pagina: a seta vive no cabecalho da conversa, e o
    // cabecalho so existe quando ha conversa aberta. Pedir isso ao GET da home passaria a
    // procurar um botao que corretamente nao esta la — teste vermelho por motivo errado.
    //
    // Sem esta seta, no telefone a pessoa entra numa conversa e so sai recarregando a pagina.
    Livewire::actingAs(test()->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => test()->conversa->id])
        ->assertSee('voltar-para-lista', false)
        ->assertSee('aria-label="Voltar para a lista"', false);
});

it('a seta de voltar nao aparece no computador', function () {
    // lg:hidden no proprio botao. No computador a lista esta ao lado o tempo todo e uma seta
    // de "voltar" para algo que nunca saiu da tela so confunde.
    Livewire::actingAs(test()->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => test()->conversa->id])
        ->assertSee('lg:hidden', false);
});
