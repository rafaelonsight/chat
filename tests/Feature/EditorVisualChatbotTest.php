<?php

use App\Filament\Resources\Chatbots\Pages\EditarFluxo;
use App\Models\{Chatbot, ChatbotAction, ChatbotEdge, Tenant, User};
use App\Services\ChatbotFluxo;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'Editor', 'slug' => 'editor']);
    TenantContext::set($this->tenant->id);
    $usuario = User::create(['name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'password', 'perfil' => 'admin', 'tenant_id' => $this->tenant->id]);
    $usuario->forceFill(['perfil_preenchido_em' => now()])->saveQuietly();
    $this->actingAs($usuario);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->bot = Chatbot::create(['nome' => 'Editor', 'ativo' => false, 'mensagem_boas_vindas' => 'Oi', 'mensagem_nao_entendi' => 'Repita']);
});
afterEach(fn () => TenantContext::forget());

it('cria grupo pelo catalogo sem publicar o fluxo', function (string $tipo) {
    Livewire::test(EditarFluxo::class, ['record' => $this->bot->id])
        ->assertSee('Biblioteca de blocos')
        ->call('adicionarAcao', $tipo);
    expect($this->bot->steps()->count())->toBe(2)
        ->and($this->bot->steps()->whereHas('actions', fn ($q) => $q->where('tipo', $tipo))->count())->toBe(1)
        ->and($this->bot->fresh()->ativo)->toBeFalse();
})->with(array_keys(ChatbotAction::TIPOS));

it('ignora um tipo desconhecido sem criar grupos', function () {
    Livewire::test(EditarFluxo::class, ['record' => $this->bot->id])
        ->call('adicionarAcao', 'desconhecido');
    expect($this->bot->steps()->count())->toBe(1);
});

it('organiza ciclos e grupos desconectados preservando as ligacoes', function () {
    $fluxo = app(ChatbotFluxo::class);
    $inicio = $fluxo->garantirInicio($this->bot);
    $a = $fluxo->criarPasso($this->bot, 100, 100, 'A');
    $b = $fluxo->criarPasso($this->bot, 100, 100, 'B');
    $solto = $fluxo->criarPasso($this->bot, 100, 100, 'Solto');
    $fluxo->ligar($inicio, $a);
    $fluxo->ligar($a, $b);
    $fluxo->ligar($b, $a);
    $antes = ChatbotEdge::orderBy('id')->get()->toArray();
    Livewire::test(EditarFluxo::class, ['record' => $this->bot->id])
        ->call('organizarGrupos')->assertDispatched('fluxo-organizado');
    expect(ChatbotEdge::orderBy('id')->get()->toArray())->toBe($antes)
        ->and($inicio->fresh()->x)->toBeLessThan($a->fresh()->x)
        ->and($a->fresh()->x)->toBeLessThan($b->fresh()->x)
        ->and($solto->fresh()->x)->toBeGreaterThan($b->fresh()->x);
});
