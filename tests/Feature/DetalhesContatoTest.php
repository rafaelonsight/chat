<?php

use App\Livewire\Inbox\ContactDetails;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

function cenarioDet(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'Atendente', 'email' => "u@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'Comercial']);
    $c->refresh();
    $c->update(['status' => 'open']);
    $ct = Contact::create(['telefone_e164' => '+5584996143373', 'nome' => 'Zap do Cliente']);
    $cv = Conversation::create(['channel_id' => $c->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now()]);

    return [$t, $u, $c, $ct, $cv];
}

function msgDet(Conversation $cv, string $direcao, string $corpo = 'oi'): Message
{
    return Message::create([
        'conversation_id' => $cv->id,
        'channel_id'      => $cv->channel_id,
        'direcao'         => $direcao,
        'tipo'            => 'text',
        'corpo'           => $corpo,
        'status'          => $direcao === 'in' ? Message::STATUS_DELIVERED : Message::STATUS_QUEUED,
    ]);
}

afterEach(fn () => TenantContext::forget());

it('o cabecalho da conversa dispara a abertura dos detalhes', function () {
    [, $u, , , $cv] = cenarioDet('dt1');

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->call('verDetalhes')
        ->assertDispatched('abrir-detalhes');
});

it('o painel comeca fechado e abre no evento', function () {
    [, $u, , , $cv] = cenarioDet('dt2');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->assertSet('aberto', false)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertSet('aberto', true)
        ->assertSet('conversationId', $cv->id);
});

it('mostra os dados do contato e do canal', function () {
    [, $u, $c, $ct, $cv] = cenarioDet('dt3');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertSee('Zap do Cliente')
        ->assertSee('+5584996143373')
        ->assertSee('Comercial');
});

it('conta mensagens recebidas e enviadas', function () {
    [, $u, , , $cv] = cenarioDet('dt4');

    msgDet($cv, 'in');
    msgDet($cv, 'in');
    $this->actingAs($u);
    msgDet($cv, 'out');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertViewHas('resumo', fn ($r) => (int) $r['total'] === 3
            && (int) $r['recebidas'] === 2
            && (int) $r['enviadas'] === 1);
});

it('renomeia o contato e avisa a lista', function () {
    [, $u, , $ct, $cv] = cenarioDet('dt5');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->set('nome', 'Joao da Silva - Fibra 300')
        ->call('salvarNome')
        ->assertHasNoErrors()
        ->assertDispatched('conversa-atualizada');

    expect($ct->refresh()->nome)->toBe('Joao da Silva - Fibra 300');
});

it('nao aceita nome vazio', function () {
    [, $u, , $ct, $cv] = cenarioDet('dt6');

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->set('nome', '   ')
        ->call('salvarNome')
        ->assertHasErrors('nome');

    expect($ct->refresh()->nome)->toBe('Zap do Cliente');
});

it('nao abre detalhes de conversa de outro tenant', function () {
    [, , , , $cvA] = cenarioDet('dt7');
    [, $uB] = cenarioDet('dt8');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cvA->id)
        ->assertViewHas('conversa', fn ($c) => $c === null);
});

it('nao renomeia contato de outro tenant', function () {
    [, , , $ctA, $cvA] = cenarioDet('dt9');
    [, $uB] = cenarioDet('dta');
    TenantContext::forget();

    Livewire::actingAs($uB)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cvA->id)
        ->set('nome', 'invadido')
        ->call('salvarNome');

    expect($ctA->refresh()->nome)->toBe('Zap do Cliente');
});

it('mostra quantas conversas o contato tem', function () {
    [$t, $u, , $ct, $cv] = cenarioDet('dtb');

    // mesmo contato falando por um segundo canal
    $outro = Channel::create(['nome' => 'Cobranca']);
    $outro->refresh();
    Conversation::create(['channel_id' => $outro->id, 'contact_id' => $ct->id]);

    Livewire::actingAs($u)
        ->test(ContactDetails::class)
        ->call('trocarConversa', $cv->id)
        ->call('alternar')
        ->assertViewHas('outrasConversas', fn ($n) => $n === 1);
});

// ============================================================ AS QUATRO ABAS ==

it('mostra as quatro abas e comeca em Detalhes', function () {
    [, $u, , , $cv] = cenarioDet('ab1');

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->assertSet('aba', 'detalhes')
        ->assertSee('Detalhes')
        ->assertSee('Arquivos')
        ->assertSee('Conversas')
        ->assertSee('Painéis');
});

it('ignora aba inventada em vez de deixar o painel em branco', function () {
    // O valor vem do navegador. Uma aba fora da lista nao casaria com nenhum @if
    // do blade: o painel ficaria vazio sem erro nenhum, que e o pior dos mundos.
    [, $u, , , $cv] = cenarioDet('ab2');

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->call('irPara', 'arquivos')
        ->assertSet('aba', 'arquivos')
        ->call('irPara', 'sei-la-o-que')
        ->assertSet('aba', 'arquivos');
});

it('a aba escolhida sobrevive a troca de conversa', function () {
    // De proposito: quem esta conferindo anexo confere de varios contatos seguidos.
    // O cabecalho com nome e avatar fica visivel em qualquer aba, entao nao da para
    // se perder sobre de quem e o anexo.
    [, $u, , $ct, $cv] = cenarioDet('ab3');

    // Outro CANAL de proposito: conversations_abertas_unicas so permite uma
    // conversa aberta por canal + contato, e a minha primeira versao deste teste
    // batia nessa trava — que esta certa.
    $outroCanal = Channel::create(['nome' => 'Financeiro']);
    $outra = Conversation::create([
        'channel_id' => $outroCanal->id, 'contact_id' => $ct->id, 'ultima_msg_em' => now(),
    ]);

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->call('irPara', 'arquivos')
        ->call('trocarConversa', $outra->id)
        ->assertSet('aba', 'arquivos');
});

it('Detalhes traz telefone com atalho do WhatsApp e caminho para o cadastro', function () {
    [, $u, , , $cv] = cenarioDet('ab4');

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->assertSee('+5584996143373')
        // so digitos: o wa.me nao aceita o sinal de mais
        ->assertSee('wa.me/5584996143373', escape: false)
        ->assertSee('Abrir cadastro completo');
});

it('Arquivos lista so mensagem com anexo', function () {
    [, $u, , , $cv] = cenarioDet('ab5');

    // Corpo LONGO e improvavel de proposito. Este teste falhava de vez em quando por causa
    // de assertDontSee('oi'): 'oi' tem duas letras, e cada resposta do Livewire carrega ids e
    // snapshots aleatorios. Uma hora sai um 'oi' dentro de um hash e o teste acusa vazamento
    // que nao houve. Assercao de AUSENCIA precisa de texto que so possa vir de um lugar.
    msgDet($cv, 'in', 'texto-puro-nao-deve-aparecer-em-arquivos');

    Message::create([
        'conversation_id' => $cv->id,
        'channel_id'      => $cv->channel_id,
        'direcao'         => 'in',
        'tipo'            => 'image',
        'status'          => Message::STATUS_DELIVERED,
        'media_path'      => 'anexos/comprovante.pdf',
        'media_mime'      => 'application/pdf',
        'media_nome'      => 'comprovante.pdf',
        'media_tamanho'   => 2048,
    ]);

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->call('irPara', 'arquivos')
        ->assertSee('comprovante.pdf')
        ->assertSee('2 KB')
        ->assertDontSee('texto-puro-nao-deve-aparecer-em-arquivos');
});

it('Arquivos avisa quando nao ha anexo, em vez de ficar vazia', function () {
    [, $u, , , $cv] = cenarioDet('ab6');

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->call('irPara', 'arquivos')
        ->assertSee('Nenhum arquivo nesta conversa');
});

it('Conversas mostra as outras conversas do contato e abre a escolhida', function () {
    [, $u, $c, $ct, $cv] = cenarioDet('ab7');

    $suporte = Channel::create(['nome' => 'Suporte']);
    $antiga = Conversation::create([
        'channel_id'    => $suporte->id,
        'contact_id'    => $ct->id,
        'ultima_msg_em' => now()->subDays(3),
    ]);

    $tela = Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->call('irPara', 'conversas');

    // a atual nao se lista dentro de si mesma
    $tela->assertSee('Suporte')->assertDontSee('Comercial');

    // O MESMO evento que a lista usa: sem ele a janela mostraria as mensagens de
    // uma conversa e o painel os detalhes de outra.
    $tela->call('abrirOutra', $antiga->id)
        ->assertDispatched('abrir-conversa', conversationId: $antiga->id);
});

it('Paineis diz que a integracao nao esta ligada, sem fingir dado', function () {
    // Aba declarada e vazia de proposito. Inventar contrato e fatura aqui faria o
    // atendente repassar numero errado ao cliente.
    [, $u, , , $cv] = cenarioDet('ab8');

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->call('irPara', 'paineis')
        ->assertSee('integração ainda não está ligada');
});

it('Detalhes mostra campo personalizado preenchido e esconde o vazio', function () {
    [, $u, , $ct, $cv] = cenarioDet('ab9');

    $contrato = \App\Models\ContactField::create([
        'nome' => 'Contrato', 'tipo' => \App\Models\ContactField::TEXTO_CURTO, 'ordem' => 1,
    ]);
    \App\Models\ContactField::create([
        'nome' => 'Plano', 'tipo' => \App\Models\ContactField::TEXTO_CURTO, 'ordem' => 2,
    ]);

    $ct->fieldValues()->create(['contact_field_id' => $contrato->id, 'valor' => '778899']);

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->assertSee('Contrato')
        ->assertSee('778899')
        // Painel de leitura: linha vazia convidaria a preencher algo que nao da
        // para editar aqui.
        ->assertDontSee('Plano');
});

// ================================ QUAL ETIQUETA ESTA POSTA TEM DE SER OBVIO ==

it('a etiqueta posta se distingue da nao posta pela COR, nao so pelo fundo', function () {
    // Nos prints do Rafael as tres etiquetas pareciam iguais: todas tinham o ponto
    // colorido, posta ou nao, e a diferenca de fundo era fraca demais. Cor que
    // aparece nas duas nao informa nada.
    [, $u, , $ct, $cv] = cenarioDet('et1');

    $posta = \App\Models\Tag::create(['nome' => 'Financeiro', 'cor' => 'verde']);
    \App\Models\Tag::create(['nome' => 'Cancelado', 'cor' => 'vermelho']);

    $ct->tags()->attach($posta->id, ['origem' => 'manual']);

    $html = Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->html();

    // A posta tem a cor da paleta e o anel grosso.
    expect($html)->toContain('bg-green-100')
        ->and($html)->toContain('ring-2')
        // A nao posta fica cinza: o ponto vermelho do "Cancelado" NAO aparece.
        ->and($html)->not->toContain('bg-red-500')
        ->and($html)->toContain('bg-gray-300');
});

it('a bolinha da etiqueta aparece na frente do nome na lista de conversas', function () {
    // O que o Rafael pediu: reconhecer a etiqueta sem abrir o contato.
    [, $u, , $ct, ] = cenarioDet('et2');

    $tag = \App\Models\Tag::create(['nome' => 'Financeiro', 'cor' => 'verde']);
    $ct->tags()->attach($tag->id, ['origem' => 'chatbot']);

    Livewire::actingAs($u)
        ->test(\App\Livewire\Inbox\ConversationList::class)
        ->assertSee('bg-green-500', escape: false);
});

it('o cabecalho da janela mostra a etiqueta com nome, ao lado do contato', function () {
    // Na lista basta a bolinha, para nao roubar espaco do nome. No cabecalho vai o
    // nome da etiqueta: e ali que a pessoa escreve a resposta, e ela precisa saber
    // que este atendimento e do Financeiro sem abrir o painel.
    [, $u, , $ct, $cv] = cenarioDet('cab1');

    $tag = \App\Models\Tag::create(['nome' => 'Financeiro', 'cor' => 'verde']);
    $ct->tags()->attach($tag->id, ['origem' => 'chatbot']);

    Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->assertSee('Financeiro')
        ->assertSee('bg-green-100', escape: false);
});

it('trocar a etiqueta no painel avisa a janela, senao o cabecalho mentiria', function () {
    // Sem esse aviso, o painel mostraria a etiqueta posta e o cabecalho continuaria
    // sem ela — duas partes da mesma tela discordando.
    [, $u, , , $cv] = cenarioDet('cab2');

    $tag = \App\Models\Tag::create(['nome' => 'Financeiro', 'cor' => 'verde']);

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->call('alternarEtiqueta', $tag->id)
        ->assertDispatched('contato-atualizado');
});

it('renomear o contato tambem avisa a janela', function () {
    [, $u, , , $cv] = cenarioDet('cab3');

    Livewire::actingAs($u)
        ->test(ContactDetails::class, ['conversationId' => $cv->id])
        ->call('alternar')
        ->set('nome', 'Rafael Paulino')
        ->call('salvarNome')
        ->assertDispatched('contato-atualizado');
});

it('a janela escuta contato-atualizado', function () {
    // Afirma o contrato, nao so o disparo: evento disparado que ninguem ouve nao
    // atualiza nada, e o teste acima passaria igual.
    [, $u, , , $cv] = cenarioDet('cab4');

    $ouvintes = Livewire::actingAs($u)
        ->test(ConversationWindow::class, ['conversationId' => $cv->id])
        ->instance()
        ->getListeners();

    expect($ouvintes)->toHaveKey('contato-atualizado');
});
