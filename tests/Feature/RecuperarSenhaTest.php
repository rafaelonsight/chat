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

it('a tela de recuperar senha existe e abre', function () {
    $this->get('/admin/password-reset/request')->assertOk();
});

it('a rota tem nome, para o link do e-mail nao quebrar', function () {
    expect(route('filament.admin.auth.password-reset.request'))->toContain('/admin/password-reset/request');
});

it('pedir recuperacao manda o aviso para o usuario certo', function () {
    Notification::fake();

    $estado = Password::broker()->sendResetLink(['email' => $this->usuario->email]);

    expect($estado)->toBe(Password::RESET_LINK_SENT);

    Notification::assertSentTo($this->usuario, ResetPassword::class);
});

it('e-mail que nao existe nao revela que nao existe', function () {
    // Responder "esse e-mail nao esta cadastrado" entrega para quem tenta adivinhar quais
    // enderecos tem conta. O broker responde generico de proposito.
    Notification::fake();

    Password::broker()->sendResetLink(['email' => 'ninguem@lugar.test']);

    Notification::assertNothingSent();
});

it('o token fica registrado, para o link poder ser conferido depois', function () {
    Notification::fake();

    Password::broker()->sendResetLink(['email' => $this->usuario->email]);

    expect(DB::table('password_reset_tokens')->where('email', $this->usuario->email)->exists())->toBeTrue();
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

it('para de avisar quando esta configurado de verdade', function () {
    config(['mail.default' => 'smtp', 'mail.from.address' => 'nao-responda@rpaulino.com.br']);

    $problemas = collect(app(Diagnostico::class)->verificar())->keyBy('chave');

    expect($problemas->has('email'))->toBeFalse();
});
