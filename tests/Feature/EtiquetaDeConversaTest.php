<?php

use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tag, Tenant, User};
use App\Services\Etiquetador;
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * Etiqueta de CONVERSA, separada da etiqueta de CONTATO.
 *
 * O PROBLEMA. Etiqueta de contato descreve a PESSOA e vale para sempre: "Cliente VIP",
 * "Inadimplente". Etiqueta de conversa descreve o que aconteceu NAQUELE atendimento:
 * "Orcamento enviado", "Reclamacao".
 *
 * Com uma coisa so, o relatorio historico mentia. "Quantos orcamentos em julho?" era
 * respondido olhando quem tem a etiqueta HOJE — e se o cliente virou "Fechado" em agosto,
 * julho encolhia sozinho. Numero que muda depois de o mes fechar e numero em que ninguem
 * confia. E o teste do fim deste arquivo prova exatamente isso.
 *
 * O CONTEXTO NASCE NA ETIQUETA, e nao no uso: assim "Cliente VIP" nem aparece na lista da
 * conversa. Nao da para marcar no lugar errado se o lugar errado nao esta na lista — e a
 * defesa esta no SERVICO, nao so no menu, porque o id chega de fora.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'etq-conversa']);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@etq.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'etq',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->pessoa->id, 'ultima_msg_em' => now(),
    ]);

    $this->doContato = Tag::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente VIP',
        'cor' => 'ambar', 'contexto' => Tag::CONTATO,
    ]);

    $this->daConversa = Tag::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Orcamento enviado',
        'cor' => 'azul', 'contexto' => Tag::CONVERSA,
    ]);

    $this->actingAs($this->pessoa);
});

// ------------------------------------------------------------- o contexto

it('etiqueta antiga continua sendo de contato', function () {
    // As que ja existiam viram 'contato', que e o que elas sempre foram. Migrar para
    // 'conversa' mudaria o significado do que ja esta marcado.
    $antiga = Tag::create(['tenant_id' => $this->conta->id, 'nome' => 'Antiga', 'cor' => 'cinza']);

    expect($antiga->fresh()->contexto)->toBe(Tag::CONTATO);
});

it('o banco recusa contexto inventado', function () {
    expect(fn () => Tag::create([
        'tenant_id' => $this->conta->id, 'nome' => 'X', 'cor' => 'cinza', 'contexto' => 'qualquer',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

// -------------------------------------------------- o servico e quem barra

it('poe etiqueta de conversa na conversa', function () {
    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('alternarEtiquetaDaConversa', $this->daConversa->id);

    expect($this->conversa->fresh()->tags->pluck('id')->all())->toContain($this->daConversa->id);
});

it('RECUSA etiqueta de contato na conversa, mesmo forcando o id', function () {
    // A defesa mora no servico e nao no menu: o id chega do navegador, e etiqueta no lugar
    // errado e exatamente o que estraga o relatorio historico.
    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('alternarEtiquetaDaConversa', $this->doContato->id);

    expect($this->conversa->fresh()->tags)->toHaveCount(0);
});

it('RECUSA etiqueta de conversa no contato', function () {
    app(Etiquetador::class)->aplicar($this->contato, [$this->daConversa->id]);

    expect($this->contato->fresh()->tags)->toHaveCount(0);
});

it('clicar de novo tira a etiqueta', function () {
    $tela = Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id]);

    $tela->call('alternarEtiquetaDaConversa', $this->daConversa->id);
    expect($this->conversa->fresh()->tags)->toHaveCount(1);

    $tela->call('alternarEtiquetaDaConversa', $this->daConversa->id);
    expect($this->conversa->fresh()->tags)->toHaveCount(0);
});

it('guarda quem colocou e por qual origem', function () {
    // Quando uma etiqueta aparecer errada, alguem vai perguntar quem colocou.
    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('alternarEtiquetaDaConversa', $this->daConversa->id);

    $pivo = $this->conversa->fresh()->tags->first()->pivot;

    expect($pivo->origem)->toBe(Etiquetador::MANUAL)
        ->and($pivo->aplicado_por)->toBe($this->pessoa->id)
        ->and($pivo->created_at)->not->toBeNull();
});

it('o menu da conversa so oferece etiqueta de conversa', function () {
    $oferecidas = Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->viewData('etiquetasDeConversa');

    expect($oferecidas->pluck('id')->all())
        ->toContain($this->daConversa->id)
        ->not->toContain($this->doContato->id);
});

// ------------------------------------------------------------- o filtro

it('filtrar por etiqueta de conversa acha pela conversa', function () {
    $this->conversa->tags()->attach($this->daConversa->id);

    $conversas = Livewire::actingAs($this->pessoa)->test(ConversationList::class)
        ->set('equipe', 'todas')->set('balde', 'meus')
        ->call('filtrarEtiqueta', (string) $this->daConversa->id)
        ->viewData('conversas');

    expect($conversas->pluck('id')->all())->toContain($this->conversa->id);
});

it('filtrar por etiqueta de contato continua achando pelo contato', function () {
    // O comportamento antigo nao pode ter mudado de carona.
    $this->contato->tags()->attach($this->doContato->id);

    $conversas = Livewire::actingAs($this->pessoa)->test(ConversationList::class)
        ->set('equipe', 'todas')->set('balde', 'meus')
        ->call('filtrarEtiqueta', (string) $this->doContato->id)
        ->viewData('conversas');

    expect($conversas->pluck('id')->all())->toContain($this->conversa->id);
});

// ------------------------------------------- o que motivou tudo: o relatorio

it('o numero do mes passado NAO muda quando a etiqueta do contato muda', function () {
    // Este e o teste que justifica o commit inteiro.
    //
    // Antes: o relatorio contava as conversas de quem tem a etiqueta HOJE. Trocar a etiqueta
    // do contato em agosto reescrevia julho. Agora a etiqueta esta presa a conversa, e o
    // passado para de se mexer.
    $this->conversa->tags()->attach($this->daConversa->id, ['created_at' => now()]);
    $this->contato->tags()->attach($this->doContato->id);

    $antes = Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Relatorios::class)
        ->viewData('porEtiqueta');

    $linhaAntes = collect($antes)->firstWhere('etiqueta', 'Orcamento enviado');
    expect($linhaAntes?->conversas)->toBe(1);

    // O cliente muda de categoria depois. Isto NAO pode mexer no numero acima.
    $this->contato->tags()->detach();
    $fechado = Tag::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Fechado', 'cor' => 'verde', 'contexto' => Tag::CONTATO,
    ]);
    $this->contato->tags()->attach($fechado->id);

    $depois = Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Relatorios::class)
        ->viewData('porEtiqueta');

    $linhaDepois = collect($depois)->firstWhere('etiqueta', 'Orcamento enviado');

    expect($linhaDepois?->conversas)->toBe(1)
        // e a etiqueta de contato nao aparece neste relatorio
        ->and(collect($depois)->pluck('etiqueta')->all())->not->toContain('Fechado');
});

it('o menu do relatorio so oferece etiqueta de conversa', function () {
    $etiquetas = Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Relatorios::class)
        ->viewData('etiquetas');

    expect($etiquetas->pluck('id')->all())
        ->toContain($this->daConversa->id)
        ->not->toContain($this->doContato->id);
});

it('a cobertura conta conversa SEM etiqueta de conversa', function () {
    // "Cem conversas etiquetadas parecem otimo ate se descobrir que houve mil."
    $semNada = Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Relatorios::class)
        ->viewData('semEtiqueta');

    expect($semNada)->toBe(1);

    $this->conversa->tags()->attach($this->daConversa->id);

    $depois = Livewire::actingAs($this->pessoa)
        ->test(App\Filament\Pages\Relatorios::class)
        ->viewData('semEtiqueta');

    expect($depois)->toBe(0);
});
