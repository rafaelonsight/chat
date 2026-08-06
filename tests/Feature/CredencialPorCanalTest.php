<?php

use App\Models\{Channel, Tenant};
use App\Services\Canais\Enviadores;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Credencial da Meta por canal.
 *
 * O que esta sob prova nao e "o token e usado" — e que o token do CLIENTE nunca seja
 * trocado pelo nosso, e que ele nao apareca em lugar onde vaza.
 */

beforeEach(function () {
    config([
        'services.meta.token'  => 'EAA-token-do-env',
        'services.meta.versao' => 'v23.0',
    ]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'cred']);
    TenantContext::set($this->tenant->id);

    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
});

afterEach(fn () => TenantContext::forget());

function canalOficial(?string $token = null): Channel
{
    return Channel::create(array_filter([
        'nome'                 => 'Oficial',
        'tipo'                 => Channel::META_CLOUD,
        'meta_phone_number_id' => '111222333',
        'meta_token'           => $token,
    ]))->refresh();
}

it('usa o token do canal quando ele tem um', function () {
    $canal = canalOficial('EAA-token-do-cliente');

    app(Enviadores::class)->para($canal)->texto($canal, '+5541999998888', 'oi');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer EAA-token-do-cliente'));
});

it('cai no token do env quando o canal ainda nao tem credencial propria', function () {
    // A reserva existe para o canal que ja funciona hoje nao parar no meio da transicao.
    $canal = canalOficial();

    app(Enviadores::class)->para($canal)->texto($canal, '+5541999998888', 'oi');

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer EAA-token-do-env'));
});

it('nunca manda a credencial de um cliente com o canal de outro', function () {
    // O erro que este teste impede e o pior de todos neste desenho: um canal enviar com a
    // credencial de outro cliente. A mensagem sairia do numero errado, para o cliente
    // errado, e cobrada na conta errada.
    $umCliente   = canalOficial('EAA-cliente-A');
    $outroCliente = Channel::create([
        'nome'                 => 'Oficial B',
        'tipo'                 => Channel::META_CLOUD,
        'meta_phone_number_id' => '999888777',
        'meta_token'           => 'EAA-cliente-B',
    ])->refresh();

    $enviadores = app(Enviadores::class);
    $enviadores->para($umCliente)->texto($umCliente, '+5541999998888', 'oi');
    $enviadores->para($outroCliente)->texto($outroCliente, '+5541999998888', 'oi');

    Http::assertSent(fn ($r) => str_contains($r->url(), '111222333')
        && $r->hasHeader('Authorization', 'Bearer EAA-cliente-A'));

    Http::assertSent(fn ($r) => str_contains($r->url(), '999888777')
        && $r->hasHeader('Authorization', 'Bearer EAA-cliente-B'));
});

it('o token vai no cabecalho e nao na URL', function () {
    // URL aparece em log de servidor, em proxy e em mensagem de excecao. Cabecalho nao.
    $canal = canalOficial('EAA-token-do-cliente');

    app(Enviadores::class)->para($canal)->texto($canal, '+5541999998888', 'oi');

    Http::assertSent(fn ($r) => ! str_contains($r->url(), 'EAA-token-do-cliente'));
});

it('o token nao fica legivel no banco', function () {
    // Quem tira um dump para depurar nao sai com credencial de cliente na mao.
    $canal = canalOficial('EAA-token-do-cliente');

    $bruto = DB::table('channels')->where('id', $canal->id)->value('meta_token');

    expect($bruto)->not->toBe('EAA-token-do-cliente')
        ->and($bruto)->not->toContain('EAA-token-do-cliente')
        // e continua legivel por quem tem a APP_KEY
        ->and($canal->fresh()->meta_token)->toBe('EAA-token-do-cliente');
});

it('canal oficial sem token nenhum estoura em vez de tentar com credencial alheia', function () {
    // Erro de configuracao tem de aparecer como erro de configuracao.
    config(['services.meta.token' => '']);

    $canal = canalOficial();

    expect(fn () => app(Enviadores::class)->para($canal)->texto($canal, '+5541999998888', 'oi'))
        ->toThrow(RuntimeException::class, 'nao tem token da Meta');

    Http::assertNothingSent();
});

it('marcar lida tambem usa o token do canal', function () {
    // Nao basta o envio: qualquer chamada em nome do cliente vai com a credencial dele.
    $canal = canalOficial('EAA-token-do-cliente');

    app(Enviadores::class)->para($canal)->marcarLida($canal, '5541999998888@s.whatsapp.net', ['wamid.A']);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer EAA-token-do-cliente'));
});
