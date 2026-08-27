<?php

use App\Events\AvisoColaboracao;
use App\Livewire\BoardItemDiscussion;
use App\Models\{BoardItem, Funnel, Tenant, User};
use App\Support\{TenantContext, TextoComLinks};
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'Equipe', 'slug' => 'colab']);
    TenantContext::set($this->tenant->id);
    $this->autor = User::create(['name' => 'Rafael', 'email' => 'autor@example.test', 'password' => 'password', 'tenant_id' => $this->tenant->id]);
    $this->colega = User::create(['name' => 'Celso', 'email' => 'colega@example.test', 'password' => 'password', 'tenant_id' => $this->tenant->id]);
    $this->actingAs($this->autor);
    $this->painel = Funnel::criarCom('Tarefas');
    $this->atributos = ['tenant_id' => $this->tenant->id, 'funnel_id' => $this->painel->id,
        'funnel_stage_id' => $this->painel->stages->first()->id, 'titulo' => 'Teste', 'responsavel_id' => $this->colega->id];
});
afterEach(fn () => TenantContext::forget());

it('notifica o responsável e não repete em salvamento sem reatribuição', function () {
    Event::fake([AvisoColaboracao::class]);
    $item = BoardItem::create($this->atributos);
    expect($this->colega->notifications()->count())->toBe(1);
    expect($this->autor->notifications()->count())->toBe(0);
    $item->update(['titulo' => 'Novo título']);
    expect($this->colega->notifications()->count())->toBe(1);
    Event::assertDispatched(AvisoColaboracao::class, fn ($e) => $e->userId === $this->colega->id && str_contains($e->url, '&item='.$item->id));
});

it('guarda conversa e deduplica responsável também mencionado', function () {
    $item = BoardItem::create($this->atributos);
    Livewire::test(BoardItemDiscussion::class, ['itemId' => $item->id])
        ->set('texto', 'Veja https://example.com')
        ->set('mencoes', [$this->colega->id, $this->colega->id])->call('enviar')->assertHasNoErrors();
    expect($item->updates()->where('tipo', 'mensagem')->count())->toBe(1);
    expect($this->colega->notifications()->count())->toBe(2);
});

it('registra mudança de status e não repete quando não mudou', function () {
    $item = BoardItem::create($this->atributos);
    $etapa = $this->painel->stages->last();
    Livewire::test(BoardItemDiscussion::class, ['itemId' => $item->id])
        ->set('status', $etapa->id)->call('alterarStatus')->call('alterarStatus')->assertHasNoErrors();
    expect($item->refresh()->funnel_stage_id)->toBe($etapa->id);
    expect($item->updates()->where('tipo', 'status')->count())->toBe(1);
});

it('não aceita status de outro painel', function () {
    $item = BoardItem::create($this->atributos);
    $outro = Funnel::criarCom('Outro');
    expect(fn () => Livewire::test(BoardItemDiscussion::class, ['itemId' => $item->id])
        ->set('status', $outro->stages->first()->id)->call('alterarStatus'))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('não abre discussão nem notifica colaborador de outra conta', function () {
    $outro = Tenant::create(['nome' => 'Outro', 'slug' => 'outro']);
    $estranho = User::create(['tenant_id' => $outro->id, 'name' => 'Outro', 'email' => 'outro@example.test', 'password' => 'password']);
    $item = BoardItem::create($this->atributos);
    Livewire::test(BoardItemDiscussion::class, ['itemId' => $item->id])
        ->set('texto', 'Interno')->set('mencoes', [$estranho->id])->call('enviar');
    expect($estranho->notifications()->count())->toBe(0);
    TenantContext::set($outro->id);
    expect(fn () => Livewire::actingAs($estranho)->test(BoardItemDiscussion::class, ['itemId' => $item->id]))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('gera links sem executar HTML ou protocolos perigosos', function () {
    $html = (string) TextoComLinks::render('<script>alert(1)</script> https://example.com?a=1&b=2 www.example.com javascript:alert(1)');
    expect($html)->not->toContain('<script>')->not->toContain('href="javascript:')
        ->toContain('href="https://example.com?a=1&amp;b=2"')->toContain('href="https://www.example.com"')
        ->toContain('noopener noreferrer');
});
