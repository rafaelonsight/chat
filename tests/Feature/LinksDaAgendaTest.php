<?php

use App\Models\{Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * LINK DA AGENDA QUE NAO LEVA A NADA. O que se perde se quebrar: o botao "Configurar agenda"
 * abre uma tela em branco (o wire:navigate engole o 404). Achado na homologacao: as telas
 * vieram da edicao Agenda com endereco fixo, sem o prefixo agenda/ que ganharam no chat.
 */

beforeEach(function () {
    Http::fake(['*' => Http::response([])]);
    $this->conta = Tenant::create(['nome' => 'Links', 'slug' => 'links']);
    TenantContext::set($this->conta->id);
    $this->admin = User::create(['tenant_id' => $this->conta->id, 'name' => 'Ana', 'email' => 'ana@links.test', 'password' => 'segredo123', 'admin' => true]);
    $this->admin->forceFill(['perfil_preenchido_em' => now()])->saveQuietly();
    $this->actingAs($this->admin->fresh());
});

afterEach(fn () => TenantContext::forget());

it('nenhum link interno das telas da agenda leva a 404', function () {
    $paginas = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods()) && str_starts_with($r->uri(), 'admin/agenda') && ! str_contains($r->uri(), '{'))
        ->map(fn ($r) => '/'.$r->uri());

    $quebrados = [];
    foreach ($paginas as $pagina) {
        preg_match_all('#href="(?:'.preg_quote(config('app.url'), '#').')?(/admin[^"?\#]*)#', $this->get($pagina)->assertOk()->getContent(), $m);
        foreach (array_unique($m[1]) as $link) {
            if (! str_contains($link, 'logout') && $this->get($link)->getStatusCode() === 404) {
                $quebrados[] = "{$pagina} -> {$link}";
            }
        }
    }

    expect($paginas)->not->toBeEmpty()
        ->and($quebrados)->toBe([]);
});
