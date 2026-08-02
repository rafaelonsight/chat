<?php

use App\Services\EvolutionService;
use Illuminate\Support\Facades\Http;

it('envia texto com a apikey no cabecalho', function () {
    Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'ABC123']], 201)]);

    $r = (new EvolutionService('http://127.0.0.1:8081', 'chave-de-teste'))
        ->sendText('t1-c1', '+5511999999999', 'ola');

    expect($r['key']['id'])->toBe('ABC123');

    Http::assertSent(fn ($req) => $req->hasHeader('apikey', 'chave-de-teste')
        && $req['number'] === '+5511999999999'
        && $req['text'] === 'ola');
});

it('cria instancia assinando os eventos necessarios', function () {
    Http::fake(['*/instance/create' => Http::response(['instance' => ['instanceName' => 't1-c1']], 201)]);

    (new EvolutionService('http://127.0.0.1:8081', 'k'))
        ->createInstance('t1-c1', 'https://chat.onsight.com.br/webhooks/evolution/1/seg');

    Http::assertSent(function ($req) {
        $eventos = $req['webhook']['events'];

        return in_array('MESSAGES_UPSERT', $eventos)
            && in_array('MESSAGES_UPDATE', $eventos)
            && in_array('CONNECTION_UPDATE', $eventos)
            && $req['webhook']['url'] === 'https://chat.onsight.com.br/webhooks/evolution/1/seg';
    });
});
