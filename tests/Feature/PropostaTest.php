<?php

use App\Livewire\Publico\Proposta as PaginaDaProposta;
use App\Models\{Funnel, FunnelStage, Proposal, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * PROPOSTA COMERCIAL.
 *
 * A DECISAO QUE SUSTENTA TUDO: proposta e uma PAGINA COM LINK, e nao um PDF anexado. Por isso os
 * testes falam de visualizacao, aceite e validade — coisas que so existem porque ha um endereco
 * publico do outro lado. PDF anexado nao conta quantas vezes foi aberto, nao tem botao de aceitar
 * e nao pode ser corrigido depois de enviado.
 */

function contaComProposta(string $slug, array $extra = []): array
{
    $conta = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($conta->id);

    $eu = User::create([
        'tenant_id' => $conta->id, 'name' => 'Rafael', 'email' => "r@{$slug}.test",
        'password' => 'segredo123', 'admin' => true,
    ]);

    $p = Proposal::create(array_merge([
        'tenant_id'    => $conta->id,
        'titulo'       => 'Atendimento em um lugar so',
        'cliente_nome' => 'Otica Central',
        'validade'     => now()->addDays(15)->toDateString(),
        'criada_por'   => $eu->id,
    ], $extra));

    return [$conta, $eu, $p];
}

afterEach(fn () => TenantContext::forget());

// ------------------------------------------------------------------ numeracao

it('numera por conta e por ano, comecando em 001', function () {
    // Sequencial POR CONTA: id global entregaria quantas propostas o sistema inteiro ja fez, e a
    // primeira proposta de um cliente novo sairia como PROP-2026-3184.
    [$conta, $eu, $p] = contaComProposta('pr1');

    expect($p->numero)->toBe('PROP-'.now()->format('Y').'-001');

    $segunda = Proposal::create([
        'tenant_id' => $conta->id, 'titulo' => 'Outra', 'cliente_nome' => 'Mercado Sul',
    ]);

    expect($segunda->numero)->toBe('PROP-'.now()->format('Y').'-002');
});

it('proposta ENVIADA nao pode ser apagada', function () {
    /*
     * O QUE GARANTE A NUMERACAO. Proposta que saiu e documento na mao do cliente: ele tem o
     * link e o numero num e-mail. Apagar aqui nao apaga la, e abriria a porta para outra
     * proposta nascer com aquele mesmo numero e conteudo diferente.
     *
     * Rascunho pode: nunca chegou a ninguem, e reusar o numero dele nao confunde nada.
     */
    [$conta, $eu, $p] = contaComProposta('pr2');

    $p->marcarEnviada();

    expect(fn () => $p->delete())->toThrow(App\Exceptions\PropostaEnviadaProtegida::class);

    expect(Proposal::count())->toBe(1);

    // E o rascunho sai normalmente.
    $rascunho = Proposal::create(['tenant_id' => $conta->id, 'titulo' => 'B', 'cliente_nome' => 'B']);
    $rascunho->delete();

    expect(Proposal::count())->toBe(1);
});

// -------------------------------------------------------------------- valores

it('separa o que se paga uma vez do que se paga todo mes', function () {
    // Somar implantacao com mensalidade daria um total que nao existe na vida real.
    [$conta, $eu, $p] = contaComProposta('pr3', ['desconto' => 500]);

    $p->itens()->createMany([
        ['descricao' => 'Implantacao', 'quantidade' => 1, 'valor_unitario' => 2400, 'recorrente' => false],
        ['descricao' => 'Chatbot', 'quantidade' => 1, 'valor_unitario' => 1200, 'recorrente' => false],
        ['descricao' => 'Plataforma', 'quantidade' => 1, 'valor_unitario' => 390, 'recorrente' => true],
        ['descricao' => 'Widget', 'quantidade' => 1, 'valor_unitario' => 90, 'recorrente' => true],
    ]);

    $p->load('itens')->recalcular();

    // 3.600 menos 500 de desconto, e a mensalidade intocada: desconto na mensalidade seria
    // desconto para sempre, e quem escreve "desconto de 500" quase nunca quer dizer isso.
    expect((float) $p->fresh()->total_unico)->toBe(3100.0)
        ->and((float) $p->fresh()->total_recorrente)->toBe(480.0);
});

it('quantidade multiplica', function () {
    [$conta, $eu, $p] = contaComProposta('pr4');

    $p->itens()->create(['descricao' => 'Hora de consultoria', 'quantidade' => 12, 'valor_unitario' => 250]);
    $p->load('itens')->recalcular();

    expect((float) $p->fresh()->total_unico)->toBe(3000.0);
});

// ---------------------------------------------------------------- a pagina

it('rascunho nao abre pelo link', function () {
    // Rascunho e texto pela metade com preco chutado. Um link vazado antes da hora custa a
    // negociacao inteira.
    [$conta, $eu, $p] = contaComProposta('pr5');

    expect($p->status)->toBe(Proposal::RASCUNHO);

    TenantContext::forget();

    $this->get('/proposta/'.$p->token)->assertNotFound();
});

it('cada abertura vira linha, e a primeira move o estado', function () {
    [$conta, $eu, $p] = contaComProposta('pr6');
    $p->marcarEnviada();

    TenantContext::forget();

    $this->get('/proposta/'.$p->token)->assertOk();
    $this->get('/proposta/'.$p->token)->assertOk();

    TenantContext::set($conta->id);

    expect($p->fresh()->visualizacoes()->count())->toBe(2)
        ->and($p->fresh()->status)->toBe(Proposal::VISTA)
        ->and($p->fresh()->vista_em)->not->toBeNull();
});

it('a previa de quem vendeu NAO conta como abertura', function () {
    /*
     * Sem isto, cada conferida do Rafael entraria na contagem e o estado pularia para "vista"
     * antes de o cliente ver qualquer coisa. Rastreamento que mente e pior que rastreamento
     * nenhum: leva a ligar na hora errada.
     */
    [$conta, $eu, $p] = contaComProposta('pr7');
    $p->marcarEnviada();

    $this->actingAs($eu)->get('/proposta/'.$p->token)->assertOk();

    expect($p->fresh()->visualizacoes()->count())->toBe(0)
        ->and($p->fresh()->status)->toBe(Proposal::ENVIADA);
});

// ---------------------------------------------------------------- o aceite

it('o aceite guarda quem, quando e de onde', function () {
    [$conta, $eu, $p] = contaComProposta('pr8');
    $p->marcarEnviada();

    TenantContext::forget();

    Livewire::test(PaginaDaProposta::class, ['token' => $p->token])
        ->set('nomeDeQuemAceita', 'Marina Duarte')
        ->call('aceitar');

    TenantContext::set($conta->id);
    $p->refresh();

    expect($p->status)->toBe(Proposal::ACEITA)
        ->and($p->aceita_por)->toBe('Marina Duarte')
        ->and($p->aceita_em)->not->toBeNull()
        ->and($p->aceita_ip)->not->toBeNull();
});

it('aceitar duas vezes nao reescreve a data do primeiro aceite', function () {
    // Dois cliques ou um recarregar de pagina nao podem mudar a hora que vale.
    [$conta, $eu, $p] = contaComProposta('pr9');
    $p->marcarEnviada();

    $p->aceitar('Marina', '10.0.0.1', 'navegador');
    $primeira = $p->fresh()->aceita_em;

    $p->fresh()->aceitar('Outra Pessoa', '10.0.0.2', 'outro');

    expect($p->fresh()->aceita_em->eq($primeira))->toBeTrue()
        ->and($p->fresh()->aceita_por)->toBe('Marina');
});

it('proposta vencida NAO pode ser aceita', function () {
    /*
     * Aceitar preco de meses atras cria um problema pior que renegociar. E "vencida" e DERIVADO
     * da data, nao um estado guardado: estado guardado precisaria de rotina diaria para virar, e
     * rotina que falha deixa proposta vencida parecendo valida.
     */
    [$conta, $eu, $p] = contaComProposta('pr10', ['validade' => now()->subDay()->toDateString()]);
    $p->marcarEnviada();

    expect($p->vencida())->toBeTrue()
        ->and($p->podeSerAceita())->toBeFalse();

    $p->aceitar('Alguem', '10.0.0.1', 'x');

    expect($p->fresh()->status)->not->toBe(Proposal::ACEITA);
});

it('o aceite avisa quem vendeu', function () {
    // Proposta aceita sem ninguem saber e proposta que espera dois dias pelo primeiro passo da
    // entrega — com o cliente do outro lado achando que ninguem viu.
    [$conta, $eu, $p] = contaComProposta('pr11');
    $p->marcarEnviada();

    TenantContext::forget();

    Livewire::test(PaginaDaProposta::class, ['token' => $p->token])
        ->set('nomeDeQuemAceita', 'Marina Duarte')
        ->call('aceitar');

    expect($eu->notifications()->count())->toBe(1)
        ->and($eu->notifications()->first()->data['title'])->toContain('aceitou a proposta');
});

it('o aceite leva a conversa para a etapa que fecha o funil', function () {
    [$conta, $eu, $p] = contaComProposta('pr12');

    $funil = Funnel::create(['nome' => 'Vendas']);
    FunnelStage::create(['funnel_id' => $funil->id, 'nome' => 'Proposta', 'ordem' => 1]);
    $ganho = FunnelStage::create(['funnel_id' => $funil->id, 'nome' => 'Ganho', 'ordem' => 2]);
    $ganho->forceFill(['encerra' => true])->save();

    $conversa = \App\Models\Conversation::create([
        'channel_id'  => \App\Models\Channel::create(['nome' => 'C'])->refresh()->id,
        'contact_id'  => \App\Models\Contact::create(['jid' => '5511999@s.whatsapp.net', 'tipo' => \App\Models\Contact::PESSOA])->id,
        'ultima_msg_em' => now(),
    ]);

    $p->forceFill(['conversation_id' => $conversa->id])->save();
    $p->marcarEnviada();
    $p->aceitar('Marina', '10.0.0.1', 'x');

    expect($conversa->fresh()->funnel_stage_id)->toBe($ganho->id);
});

it('sem funil configurado, o aceite funciona igual', function () {
    // O aceite do cliente nao pode falhar porque o painel de vendas ainda nao foi montado.
    [$conta, $eu, $p] = contaComProposta('pr13');
    $p->marcarEnviada();

    $p->aceitar('Marina', '10.0.0.1', 'x');

    expect($p->fresh()->status)->toBe(Proposal::ACEITA);
});
