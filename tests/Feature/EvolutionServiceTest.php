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

it('edita mensagem com a chave completa', function () {
    Http::fake(['*/chat/updateMessage/*' => Http::response(['status' => 'ok'])]);

    (new EvolutionService('http://127.0.0.1:8081', 'k'))->updateMessage(
        'instancia', '+5541999990000', ['external_id' => 'MSG1', 'minha' => true], 'corrigido',
    );

    Http::assertSent(fn ($req) => str_contains($req->url(), '/chat/updateMessage/instancia')
        && $req['number'] === '+5541999990000'
        && $req['key']['id'] === 'MSG1'
        && $req['key']['remoteJid'] === '5541999990000@s.whatsapp.net'
        && $req['text'] === 'corrigido');
});

it('envia mencoes de grupo como numeros aceitos pela Evolution', function () {
    Http::fake(['*/message/sendText/*' => Http::response(['key' => ['id' => 'MEN1']], 201)]);

    (new EvolutionService('http://127.0.0.1:8081', 'k'))->sendText(
        'instancia',
        '120363000000000000@g.us',
        '@Celso e @Rafael',
        null,
        ['5541984606033@s.whatsapp.net', '5541984919939:2@s.whatsapp.net'],
    );

    Http::assertSent(fn ($req) => $req['mentioned'] === ['5541984606033', '5541984919939']);
});

it('consulta participantes enriquecidos do grupo', function () {
    Http::fake(['*/group/participants/*' => Http::response([
        ['id' => '5541984606033@s.whatsapp.net', 'name' => 'Celso'],
    ])]);

    $r = (new EvolutionService('http://127.0.0.1:8081', 'k'))
        ->groupParticipants('instancia', '120363000000000000@g.us');

    expect($r[0]['name'])->toBe('Celso');
    Http::assertSent(fn ($req) => str_contains($req->url(), '/group/participants/instancia')
        && str_contains($req->url(), 'groupJid=120363000000000000%40g.us'));
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
