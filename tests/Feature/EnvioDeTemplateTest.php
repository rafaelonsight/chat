<?php

use App\Jobs\SendTemplateMessage;
use App\Models\{Channel, Contact, Conversation, Message, MetaTemplate, Tenant};
use App\Services\Canais\Enviadores;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/*
 * Envio de template aprovado.
 *
 * Nao ha prova ao vivo possivel enquanto a conta do Rafael estiver restrita (erro 130497 da
 * Meta: empresa nao verificada com numero de teste nao fala com o Brasil). Entao aqui o
 * teste e a unica prova — e por isso ele cobre o FORMATO do que sai, campo por campo, em vez
 * de so "chamou a API".
 */

beforeEach(function () {
    config(['services.meta.token' => 'EAA-env', 'services.meta.versao' => 'v23.0']);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'envtpl']);
    TenantContext::set($this->tenant->id);

    $this->canal = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111', 'meta_waba_id' => '362',
    ])->refresh();

    $this->contato = Contact::create(['nome' => 'Rafael', 'telefone_e164' => '+5541984919939']);

    $this->conversa = Conversation::abertaOuNova($this->canal->id, $this->contato->id);

    // O stub le de PROPRIEDADES do teste. Http::fake chamado uma segunda vez nao
    // substitui o primeiro — a definicao original vence e a nova e ignorada em silencio.
    // Quem precisa de outra resposta troca a propriedade; ninguem redefine a fiacao.
    $this->corpoDaMeta = ['messages' => [['id' => 'wamid.TPL']]];
    $this->statusDaMeta = 200;

    Http::fake(['*' => fn () => Http::response($this->corpoDaMeta, $this->statusDaMeta)]);
});

afterEach(function () {
    TenantContext::forget();
    Carbon::setTestNow();
});

function templateSalvo(array $sobrescreve = []): MetaTemplate
{
    return MetaTemplate::create(array_merge([
        'meta_waba_id' => '362',
        'meta_id'      => '1',
        'nome'         => 'aviso_de_manutencao',
        'idioma'       => 'pt_BR',
        'categoria'    => 'UTILITY',
        'status'       => MetaTemplate::APROVADO,
        'corpo'        => 'Prezado {{1}}, sua fatura {{2}} vence hoje.',
        'variaveis'    => 2,
        'suportado'    => true,
    ], $sobrescreve));
}

function mensagemDeTemplate(MetaTemplate $modelo, array $valores = []): Message
{
    return Message::create([
        'conversation_id' => test()->conversa->id,
        'channel_id'      => test()->canal->id,
        'direcao'         => 'out',
        'tipo'            => 'template',
        'corpo'           => $modelo->renderizar($valores),
        'status'          => Message::STATUS_QUEUED,
    ]);
}

function despachar(Message $mensagem, MetaTemplate $modelo, array $valores = []): void
{
    (new SendTemplateMessage($mensagem->id, $modelo->id, $valores))->handle(app(Enviadores::class));
}

// ================================================================ o que sai

it('manda o nome e o idioma do template, e os valores na ordem', function () {
    // O que vai para a Meta e o NOME mais os parametros. O texto montado nunca sai: quem
    // monta o texto final e o WhatsApp, a partir do template que ele aprovou.
    $modelo = templateSalvo();
    $m = mensagemDeTemplate($modelo, ['Rafael', '12345']);

    despachar($m, $modelo, ['Rafael', '12345']);

    Http::assertSent(function ($r) {
        $c = $r->data();

        return $c['type'] === 'template'
            && $c['to'] === '5541984919939'
            && $c['template']['name'] === 'aviso_de_manutencao'
            && $c['template']['language']['code'] === 'pt_BR'
            && $c['template']['components'][0]['type'] === 'body'
            && $c['template']['components'][0]['parameters'][0]['text'] === 'Rafael'
            && $c['template']['components'][0]['parameters'][1]['text'] === '12345';
    });

    expect($m->fresh()->status)->toBe(Message::STATUS_SENT)
        ->and($m->fresh()->external_id)->toBe('wamid.TPL');
});

it('template sem variavel sai sem a lista de parametros', function () {
    // Mandar "components" vazio faz a Meta recusar com erro de parametro.
    $modelo = templateSalvo(['nome' => 'ola', 'corpo' => 'Ola!', 'variaveis' => 0]);
    $m = mensagemDeTemplate($modelo);

    despachar($m, $modelo);

    Http::assertSent(fn ($r) => ! array_key_exists('components', $r->data()['template']));

    expect($m->fresh()->status)->toBe(Message::STATUS_SENT);
});

it('ENVIA com a janela de 24h FECHADA — e a razao de existir do template', function () {
    // Se este teste falhar, o recurso perdeu o proposito: com a janela aberta o atendente
    // manda texto livre e nao precisa de template nenhum.
    $this->conversa->forceFill(['ultima_entrada_em' => now()->subHours(30)])->save();

    expect($this->conversa->refresh()->podeEnviarLivre())->toBeFalse();

    $modelo = templateSalvo();
    $m = mensagemDeTemplate($modelo, ['Rafael', '999']);

    despachar($m, $modelo, ['Rafael', '999']);

    expect($m->fresh()->status)->toBe(Message::STATUS_SENT);
});

// =========================================================== o que nao sai

it('recusa template de formato que nao sabemos montar, ANTES de chamar a Meta', function () {
    $modelo = templateSalvo([
        'nome' => 'com_imagem', 'variaveis' => 0, 'suportado' => false,
        'motivo_nao_suportado' => 'cabeçalho de image: exige enviar o arquivo antes',
    ]);
    $m = mensagemDeTemplate($modelo);

    despachar($m, $modelo);

    Http::assertNothingSent();

    expect($m->fresh()->status)->toBe(Message::STATUS_FAILED)
        ->and($m->fresh()->erro)->toContain('cabeçalho de image');
});

it('recusa template ainda em analise na Meta', function () {
    $modelo = templateSalvo(['status' => 'PENDING']);
    $m = mensagemDeTemplate($modelo);

    despachar($m, $modelo, ['Rafael', '1']);

    Http::assertNothingSent();

    expect($m->fresh()->erro)->toContain('aguardando aprovação');
});

it('recusa quantidade de valores diferente do que o template pede', function () {
    // Deixar passar devolveria "number of parameters does not match" da Meta, erro que
    // parece nosso e nao diz o que fazer.
    $modelo = templateSalvo();
    $m = mensagemDeTemplate($modelo);

    despachar($m, $modelo, ['Rafael']);

    Http::assertNothingSent();

    expect($m->fresh()->erro)->toContain('precisa de 2 valor(es) e recebeu 1');
});

it('recusa valor vazio, dizendo qual', function () {
    $modelo = templateSalvo();
    $m = mensagemDeTemplate($modelo);

    despachar($m, $modelo, ['Rafael', '   ']);

    Http::assertNothingSent();

    expect($m->fresh()->erro)->toContain('valor 2');
});

it('recusa valor com quebra de linha, que a Meta rejeita', function () {
    $modelo = templateSalvo();
    $m = mensagemDeTemplate($modelo);

    despachar($m, $modelo, ["Rafael\nPaulino", '123']);

    Http::assertNothingSent();

    expect($m->fresh()->erro)->toContain('quebra de linha');
});

it('canal da Evolution nao envia template, e diz por que', function () {
    $evolution = Channel::create(['nome' => 'Pessoal', 'tipo' => Channel::EVOLUTION])->refresh();
    $conversa = Conversation::abertaOuNova($evolution->id, $this->contato->id);

    $modelo = templateSalvo();

    $m = Message::create([
        'conversation_id' => $conversa->id, 'channel_id' => $evolution->id,
        'direcao' => 'out', 'tipo' => 'template', 'corpo' => 'x',
        'status' => Message::STATUS_QUEUED,
    ]);

    (new SendTemplateMessage($m->id, $modelo->id, ['a', 'b']))->handle(app(Enviadores::class));

    Http::assertNothingSent();

    expect($m->fresh()->erro)->toContain('so na API oficial');
});

it('template apagado entre enfileirar e enviar falha com o motivo certo', function () {
    $modelo = templateSalvo();
    $m = mensagemDeTemplate($modelo, ['a', 'b']);
    $id = $modelo->id;
    $modelo->delete();

    (new SendTemplateMessage($m->id, $id, ['a', 'b']))->handle(app(Enviadores::class));

    expect($m->fresh()->erro)->toContain('removido antes do envio');
});

// ============================================================== retentativa

it('erro nosso de montagem NAO retenta', function () {
    // Retentar tres vezes um template sem suporte enche o Horizon de erro repetido e
    // esconde falha de verdade no meio.
    $modelo = templateSalvo(['suportado' => false, 'motivo_nao_suportado' => 'carrossel']);
    $m = mensagemDeTemplate($modelo);

    expect(fn () => despachar($m, $modelo))->not->toThrow(Throwable::class);
});

it('falha da Meta RETENTA, porque pode dar certo depois', function () {
    $this->corpoDaMeta = ['error' => ['message' => 'temporariamente indisponivel']];
    $this->statusDaMeta = 500;

    $modelo = templateSalvo();
    $m = mensagemDeTemplate($modelo, ['a', 'b']);

    expect(fn () => despachar($m, $modelo, ['a', 'b']))
        ->toThrow(Illuminate\Http\Client\RequestException::class);

    expect($m->fresh()->status)->toBe(Message::STATUS_FAILED);
});
