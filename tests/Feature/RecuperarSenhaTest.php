<?php

use App\Models\{Tenant, User};
use App\Services\Diagnostico;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\{Notification, Password};

/*
 * Recuperacao de senha.
 *
 * Nao existia. Funcionario do cliente que esquecesse a senha dependia de alguem com acesso ao
 * banco — o que ate hoje significava eu. Para revender, isso nao se sustenta.
 */

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'senha']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Atendente',
        'email' => 'atendente@cliente.test', 'password' => 'segredo-antigo',
    ]);
});

afterEach(fn () => TenantContext::forget());

it('as antigas rotas de recuperação por senha não existem', function () {
    $this->get('/admin/password-reset/request')->assertNotFound();
    $this->get('/admin/password-reset/reset/token')->assertNotFound();
});

// ================================================ o diagnostico denuncia o silencio

it('avisa quando o e-mail nao esta configurado', function () {
    // Com MAIL_MAILER vazio o Laravel escreve no log e a tela diz "enviamos". Falha
    // silenciosa e o pior tipo: so deixa de ser silenciosa se alguem disser em voz alta.
    config(['mail.default' => 'log']);

    $problemas = collect(app(Diagnostico::class)->verificar())->keyBy('chave');

    expect($problemas->has('email'))->toBeTrue()
        ->and($problemas['email']['nivel'])->toBe(Diagnostico::AVISO)
        ->and($problemas['email']['mensagem'])->toContain('nao chegam a ninguem');
});

it('avisa quando o remetente ainda e o de exemplo', function () {
    config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@example.com']);

    $problemas = collect(app(Diagnostico::class)->verificar())->keyBy('chave');

    expect($problemas->has('email'))->toBeTrue()
        ->and($problemas['email']['mensagem'])->toContain('Remetente');
});

it('continua avisando com tudo configurado, enquanto nenhum e-mail tiver saido', function () {
    // Este teste MUDOU DE AFIRMACAO, e a mudanca e o assunto.
    //
    // Antes ele dizia: configurado, entao para de avisar. Descobri na pratica que a frase e
    // falsa — gravei host, porta, usuario e senha corretos e o envio voltou 535, senha
    // recusada. Configuracao preenchida nao prova entrega, e um diagnostico que diz "tudo
    // certo" sobre um sistema que nao manda e-mail e pior que um diagnostico que nao existe.
    config(['mail.default' => 'smtp', 'mail.from.address' => 'nao-responda@rpaulino.com.br']);

    $problemas = collect(app(Diagnostico::class)->verificar())->keyBy('chave');

    expect($problemas->has('email'))->toBeTrue()
        ->and($problemas['email']['mensagem'])->toContain('nenhum envio');
});

it('so para de avisar depois que um envio foi aceito', function () {
    config(['mail.default' => 'smtp', 'mail.from.address' => 'nao-responda@rpaulino.com.br']);
    App\Models\SystemSetting::gravar('email.ultimo_envio', now()->toIso8601String());

    $problemas = collect(app(Diagnostico::class)->verificar())->keyBy('chave');

    expect($problemas->has('email'))->toBeFalse();
});
