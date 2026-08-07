<?php

use App\Models\SystemSetting;
use App\Services\Diagnostico;
use Illuminate\Support\Facades\Mail;

/*
 * O diagnostico de e-mail deixa de confundir CONFIGURADO com FUNCIONANDO.
 *
 * O buraco era meu e apareceu na hora errada: gravei host, porta, usuario e senha do SMTP, o
 * Diagnostico olhou a configuracao, gostou do que viu e escreveu "Tudo certo". Na mesma hora,
 * um envio de verdade voltava 535 — senha recusada. A tela dizia que estava tudo bem sobre um
 * sistema incapaz de mandar um unico e-mail.
 *
 * Configuracao preenchida nao e prova de nada. A unica prova de que o caminho existe e ter
 * passado por ele.
 */

beforeEach(function () {
    // Um mailer com NOME proprio e transporte 'array'.
    //
    // Nao da para usar 'array' direto aqui: a primeira verificacao trata log e array como
    // "e-mail nao configurado" — corretamente, porque nesses transportes nada sai da maquina.
    // Ela responderia antes e este arquivo nunca chegaria a verificacao que quer testar.
    // Com nome proprio, a configuracao parece de gente e o envio continua sem tocar a rede.
    config([
        'mail.default'        => 'teste',
        'mail.mailers.teste'  => ['transport' => 'array'],
        'mail.from.address'   => 'nao-responda@teste.local',
    ]);
});

it('avisa quando nunca saiu e-mail nenhum, mesmo com tudo configurado', function () {
    $achados = collect(app(Diagnostico::class)->verificar())->firstWhere('chave', 'email');

    expect($achados)->not->toBeNull()
        ->and($achados['nivel'])->toBe(Diagnostico::AVISO)
        ->and($achados['mensagem'])->toContain('nenhum envio');
});

it('para de avisar depois que um e-mail sai de verdade', function () {
    Mail::raw('teste', fn ($m) => $m->to('alguem@teste.local')->subject('t'));

    $achados = collect(app(Diagnostico::class)->verificar())->firstWhere('chave', 'email');

    expect($achados)->toBeNull();
});

it('anota a data do envio, e nao so um sim', function () {
    Mail::raw('teste', fn ($m) => $m->to('alguem@teste.local')->subject('t'));

    $quando = SystemSetting::ler('email.ultimo_envio');

    expect($quando)->not->toBeNull()
        ->and(\Illuminate\Support\Carbon::parse($quando)->diffInMinutes(now()))->toBeLessThan(2);
});

it('nao deixa um envio antigo esconder um transporte quebrado', function () {
    // Ordem das verificacoes importa. Se alguem trocar o MAIL_MAILER para log amanha, o
    // registro de ontem nao pode fazer o diagnostico calar sobre o de hoje.
    SystemSetting::gravar('email.ultimo_envio', now()->subDay()->toIso8601String());

    config(['mail.default' => 'log']);

    $achados = collect(app(Diagnostico::class)->verificar())->firstWhere('chave', 'email');

    expect($achados)->not->toBeNull()
        ->and($achados['mensagem'])->toContain('nao configurado');
});

it('o registro sobrevive a mais de um envio, guardando o ultimo', function () {
    SystemSetting::gravar('email.ultimo_envio', now()->subDays(3)->toIso8601String());

    Mail::raw('teste', fn ($m) => $m->to('alguem@teste.local')->subject('t'));

    // updateOrCreate e nao create: chave unica, uma linha so. Sem isso a tabela cresceria
    // uma linha por e-mail enviado, para sempre.
    expect(SystemSetting::where('chave', 'email.ultimo_envio')->count())->toBe(1)
        ->and(\Illuminate\Support\Carbon::parse(SystemSetting::ler('email.ultimo_envio'))
            ->diffInMinutes(now()))->toBeLessThan(2);
});
