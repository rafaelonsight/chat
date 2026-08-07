<?php

use App\Filament\Pages\Relatorios;
use App\Models\{Channel, Contact, Conversation, Message, Tag, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'rel']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Gestor',
        'email' => 'gestor@rel.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create(['nome' => 'Principal'])->refresh();

    // ETIQUETAS DE CONVERSA. Este relatorio passou a agrupar por elas quando a
    // etiqueta de conversa foi separada da de contato. Antes ele contava as conversas
    // de quem tem a etiqueta HOJE, e o numero de julho encolhia quando o cliente
    // mudava de categoria em agosto.
    $this->financeiro = Tag::create(['nome' => 'Financeiro', 'cor' => 'verde', 'contexto' => Tag::CONVERSA]);
    $this->suporte = Tag::create(['nome' => 'Suporte', 'cor' => 'azul', 'contexto' => Tag::CONVERSA]);
});

afterEach(fn () => TenantContext::forget());

/** Conversa com mensagens, opcionalmente etiquetada. A etiqueta vai na CONVERSA. */
function comEtiqueta(string $telefone, ?Tag $tag, int $entradas = 1, int $saidas = 1): Conversation
{
    $contato = Contact::create(['telefone_e164' => $telefone, 'nome' => 'C'.$telefone]);

    $conversa = Conversation::create([
        'channel_id' => test()->canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    if ($tag) {
        $conversa->tags()->attach($tag->id, ['origem' => 'manual']);
    }

    foreach (range(1, $entradas) as $i) {
        Message::create([
            'conversation_id' => $conversa->id, 'channel_id' => test()->canal->id,
            'direcao' => 'in', 'tipo' => 'text', 'corpo' => 'oi', 'status' => Message::STATUS_DELIVERED,
        ]);
    }

    foreach (range(1, $saidas) as $i) {
        Message::create([
            'conversation_id' => $conversa->id, 'channel_id' => test()->canal->id,
            'direcao' => 'out', 'tipo' => 'text', 'corpo' => 'ola', 'status' => Message::STATUS_SENT,
        ]);
    }

    return $conversa;
}

it('a quebra por etiqueta conta as conversas de cada etiqueta', function () {
    comEtiqueta('+5511000000001', $this->financeiro);
    comEtiqueta('+5511000000002', $this->financeiro);
    comEtiqueta('+5511000000003', $this->suporte);

    $dados = Livewire::actingAs($this->admin)->test(Relatorios::class)->instance()->getViewData();

    $porEtiqueta = collect($dados['porEtiqueta'])->keyBy('etiqueta');

    expect((int) $porEtiqueta['Financeiro']->conversas)->toBe(2)
        ->and((int) $porEtiqueta['Suporte']->conversas)->toBe(1);
});

it('conta separado quem nao tem etiqueta, senao nao da para saber a cobertura', function () {
    // Cem conversas etiquetadas parecem otimo ate se descobrir que houve mil.
    comEtiqueta('+5511000000001', $this->financeiro);
    comEtiqueta('+5511000000002', null);
    comEtiqueta('+5511000000003', null);

    $dados = Livewire::actingAs($this->admin)->test(Relatorios::class)->instance()->getViewData();

    expect($dados['semEtiqueta'])->toBe(2);
});

it('conversa com duas etiquetas aparece nas duas: a soma passa do total, e e isso mesmo', function () {
    // Comportamento declarado, nao acidente: a tela avisa. Um teste guarda a decisao
    // para ninguem "consertar" isso depois dividindo conversa entre etiquetas.
    $contato = Contact::create(['telefone_e164' => '+5511000000009', 'nome' => 'Dois']);

    $conversa = Conversation::create([
        'channel_id' => $this->canal->id, 'contact_id' => $contato->id, 'ultima_msg_em' => now(),
    ]);

    $conversa->tags()->attach([$this->financeiro->id, $this->suporte->id], ['origem' => 'manual']);

    $dados = Livewire::actingAs($this->admin)->test(Relatorios::class)->instance()->getViewData();

    $soma = collect($dados['porEtiqueta'])->sum(fn ($l) => (int) $l->conversas);

    expect($soma)->toBe(2)
        ->and($dados['resumo']['conversas'])->toBe(1);
});

it('o filtro por etiqueta vale para TODOS os numeros, nao so para a tabela', function () {
    // O risco real: filtro que pega em parte da tela faz o gestor comparar conversas de
    // um recorte com mensagens de outro sem perceber.
    comEtiqueta('+5511000000001', $this->financeiro, entradas: 2, saidas: 3);
    comEtiqueta('+5511000000002', $this->suporte, entradas: 5, saidas: 5);

    $tela = Livewire::actingAs($this->admin)->test(Relatorios::class);

    $tudo = $tela->instance()->getViewData();
    expect($tudo['resumo']['conversas'])->toBe(2)
        ->and($tudo['resumo']['mensagens'])->toBe(15);

    $tela->set('etiqueta', (string) $this->financeiro->id);
    $so = $tela->instance()->getViewData();

    expect($so['resumo']['conversas'])->toBe(1)
        // 2 entradas + 3 saidas da conversa do Financeiro, e nada do Suporte
        ->and($so['resumo']['mensagens'])->toBe(5)
        ->and($so['resumo']['recebidas'])->toBe(2)
        ->and($so['resumo']['enviadas'])->toBe(3);
});

it('o filtro tambem recorta a quebra por canal', function () {
    comEtiqueta('+5511000000001', $this->financeiro);
    comEtiqueta('+5511000000002', $this->suporte);

    $tela = Livewire::actingAs($this->admin)->test(Relatorios::class)
        ->set('etiqueta', (string) $this->financeiro->id);

    $porCanal = collect($tela->instance()->getViewData()['porCanal']);

    expect((int) $porCanal->firstWhere('canal', 'Principal')->conversas)->toBe(1);
});

it('etiqueta apagada volta para Todas em vez de zerar o relatorio sem explicacao', function () {
    comEtiqueta('+5511000000001', $this->financeiro);

    $id = $this->suporte->id;
    $this->suporte->delete();

    $tela = Livewire::actingAs($this->admin)->test(Relatorios::class)
        ->set('etiqueta', (string) $id);

    expect($tela->get('etiqueta'))->toBeNull()
        ->and($tela->instance()->getViewData()['resumo']['conversas'])->toBe(1);
});

it('atendente nao acessa relatorio', function () {
    // O recorte por etiqueta nao abre porta nova: continua sendo dado de gestao.
    $atendente = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Atendente',
        'email' => 'at@rel.test', 'password' => 'segredo123',
    ]);

    $this->actingAs($atendente);

    expect(Relatorios::canAccess())->toBeFalse();
});
