<?php

use App\Jobs\SendTextMessage;
use App\Models\{Channel, Contact, Conversation, Message, Tenant};
use App\Services\Canais\{Enviadores, FalhaDoProvedor};
use App\Support\TenantContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/*
 * O que retenta e o que nao retenta.
 *
 * Antes, qualquer falha era relancada e o Horizon tentava tres vezes — inclusive
 * "empresa restrita neste pais", que nao muda por repeticao. Tres erros identicos no lugar
 * de um, e ruido repetido esconde a falha de verdade que aparece no meio.
 */

beforeEach(function () {
    config(['services.meta.token' => 'EAA-env', 'services.meta.versao' => 'v23.0']);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'retry']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111', 'meta_waba_id' => '362',
    ])->refresh();

    $this->contato = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5541984919939']);
    $this->conversa = Conversation::abertaOuNova($this->canal->id, $this->contato->id);
    // Janela aberta: o que esta sob teste e a retentativa, nao a trava da janela.
    $this->conversa->forceFill(['ultima_entrada_em' => now()])->save();

    $this->corpoDaMeta = ['messages' => [['id' => 'wamid.X']]];
    $this->statusDaMeta = 200;

    // O stub le de propriedades do teste: Http::fake chamado de novo nao substitui o
    // primeiro, e o segundo e ignorado em silencio.
    Http::fake(['*' => fn () => Http::response($this->corpoDaMeta, $this->statusDaMeta)]);
});

afterEach(fn () => TenantContext::forget());

function mensagemNaFilaDeSaida(): Message
{
    return Message::create([
        'conversation_id' => test()->conversa->id,
        'channel_id'      => test()->canal->id,
        'direcao'         => 'out',
        'tipo'            => 'text',
        'corpo'           => 'oi',
        'status'          => Message::STATUS_QUEUED,
    ]);
}

function enviar(Message $m): void
{
    (new SendTextMessage($m->id))->handle(app(Enviadores::class));
}

/** Resposta de erro da Meta com codigo, como ela responde de verdade. */
function erroDaMeta(int $codigo, string $mensagem, int $http = 400): array
{
    return ['error' => ['message' => $mensagem, 'code' => $codigo, 'type' => 'OAuthException']];
}

// ====================================================== nao retenta o definitivo

it('restricao de pais NAO retenta', function () {
    // Foi o erro real do Rafael: 130497, empresa nao verificada com numero de teste.
    // Tentar de novo daria exatamente o mesmo resultado.
    $this->corpoDaMeta = erroDaMeta(130497, 'Business account is restricted from messaging users in this country.');
    $this->statusDaMeta = 400;

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))->not->toThrow(Throwable::class);

    expect($m->fresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->fresh()->erro)->toContain('restricted');
});

it('destinatario fora da lista permitida NAO retenta', function () {
    $this->corpoDaMeta = erroDaMeta(131030, 'Recipient phone number not in allowed list');
    $this->statusDaMeta = 400;

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))->not->toThrow(Throwable::class);
});

it('token invalido NAO retenta: retentar nao renova credencial', function () {
    $this->corpoDaMeta = erroDaMeta(190, 'Error validating access token: Session has expired');
    $this->statusDaMeta = 401;

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))->not->toThrow(Throwable::class);
});

// ============================================================ retenta o passageiro

it('provedor fora do ar RETENTA', function () {
    $this->corpoDaMeta = ['error' => ['message' => 'instavel']];
    $this->statusDaMeta = 503;

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))->toThrow(RequestException::class);

    // Marcada como falha mesmo assim: a tela nao pode dizer "enviada" enquanto a fila
    // ainda tenta. Se a retentativa der certo, o proprio job corrige o status.
    expect($m->fresh()->status)->toBe(Message::STATUS_FAILED);
});

it('limite de taxa RETENTA, porque esperar resolve', function () {
    $this->corpoDaMeta = erroDaMeta(131056, 'Too many messages sent to this number');
    $this->statusDaMeta = 400;

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))->toThrow(RequestException::class);
});

it('429 RETENTA mesmo sem codigo da Meta no corpo', function () {
    $this->corpoDaMeta = ['erro' => 'devagar'];
    $this->statusDaMeta = 429;

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))->toThrow(RequestException::class);
});

// ==================================================== erro nosso continua alto

it('canal sem Phone Number ID RETENTA, porque e erro de configuracao', function () {
    // A distincao que este par de testes protege: recusa do provedor fica em silencio
    // (o motivo esta na bolha), mas defeito de configuracao continua aparecendo no
    // Horizon — porque alguem precisa consertar, e ninguem conserta o que nao ve.
    $this->canal->forceFill(['meta_phone_number_id' => null])->save();

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))
        ->toThrow(App\Services\Canais\ConfiguracaoInvalida::class, 'nao tem Phone Number ID');

    Http::assertNothingSent();
});

// ================================================================= a regra em si

it('erro que nao e de rede nunca e transitorio', function () {
    // Falha nossa de montagem nao muda por retentar.
    expect(FalhaDoProvedor::transitoria(new RuntimeException('template sem suporte')))->toBeFalse();
});

it('4xx desconhecido nao retenta: errar para o lado de nao repetir', function () {
    // Codigo novo da Meta que ninguem mapeou cai aqui. Nao repetir e o lado seguro.
    $this->corpoDaMeta = erroDaMeta(999999, 'algo que a Meta inventou depois');
    $this->statusDaMeta = 400;

    $m = mensagemNaFilaDeSaida();

    expect(fn () => enviar($m))->not->toThrow(Throwable::class);
});
