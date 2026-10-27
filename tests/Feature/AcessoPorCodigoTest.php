<?php
use App\Models\{Tenant, User};
use App\Services\CodigoDeAcesso;
use Illuminate\Support\Facades\{DB, Mail, RateLimiter};

beforeEach(function () {
    config(['acesso.mailer' => 'array']);
    config(['mail.default' => 'array']);
    $this->usuario = User::factory()->create(['email' => 'acesso@example.test']);
});

it('exibe login sem senha e desativa recuperação por senha', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Receber token por e-mail')->assertDontSee('type="password"', false);
    $this->get('/admin/password-reset/request')->assertNotFound();
});

it('não cria usuário quando o email não está cadastrado', function () {
    $this->post('/acesso/solicitar', ['email' => 'desconhecido@example.test', 'painel' => 'admin'])->assertRedirect('/admin/login');
    expect(User::withoutGlobalScopes()->count())->toBe(1);
    expect(DB::table('codigos_de_acesso')->count())->toBe(0);
});

function inserirCodigoTeste(User $usuario, string $sessao, string $codigo = '123456', string $painel = 'admin'): string {
    $id = (string) Illuminate\Support\Str::uuid();
    DB::table('codigos_de_acesso')->insert(['id'=>$id,'user_id'=>$usuario->id,'painel'=>$painel,'sessao_hash'=>hash('sha256',$sessao),'codigo_hash'=>hash_hmac('sha256',$codigo,config('app.key')),'tentativas'=>0,'expira_em'=>now()->addMinutes(10),'created_at'=>now()]);
    return $id;
}

it('aceita código uma única vez e somente na sessão solicitante', function () {
    $id = inserirCodigoTeste($this->usuario, 'sessao-correta');
    $servico = app(CodigoDeAcesso::class);
    expect($servico->consumir($id, '123456', 'outra-sessao'))->toBeNull();
    expect($servico->consumir($id, '123456', 'sessao-correta')?->id)->toBe($this->usuario->id);
    expect($servico->consumir($id, '123456', 'sessao-correta'))->toBeNull();
});

it('recusa código expirado e limita cinco tentativas', function () {
    $id = inserirCodigoTeste($this->usuario, 'sessao');
    $servico = app(CodigoDeAcesso::class);
    for ($n=0;$n<5;$n++) expect($servico->consumir($id,'000000','sessao'))->toBeNull();
    expect($servico->consumir($id,'123456','sessao'))->toBeNull();
    $novo = inserirCodigoTeste($this->usuario, 'sessao');
    DB::table('codigos_de_acesso')->where('id',$novo)->update(['expira_em'=>now()->subMinute()]);
    expect($servico->consumir($novo,'123456','sessao'))->toBeNull();
});

it('cliente não recebe acesso ao painel de gestão', function () {
    $id = inserirCodigoTeste($this->usuario, 'sessao', '123456', 'revenda');
    expect(app(CodigoDeAcesso::class)->consumir($id,'123456','sessao'))->toBeNull();
});

it('login concluído regenera sessão e autentica usuário', function () {
    $this->withoutExceptionHandling();
    $this->get('/admin/login');
    $sessao = session()->getId();
    $id = inserirCodigoTeste($this->usuario, $sessao);
    $this->withCookie(config('session.cookie'), $sessao)->withSession(['acesso.codigo_id'=>$id,'acesso.painel'=>'admin'])
        ->post('/acesso/confirmar',['codigo'=>'123456'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($this->usuario);
    expect(DB::table('codigos_de_acesso')->where('id',$id)->value('usado_em'))->not->toBeNull();
});

it('gera código, entrega ao transporte e guarda somente hash', function () {
    $corpo = null;
    Illuminate\Support\Facades\Event::listen(Illuminate\Mail\Events\MessageSending::class, function ($evento) use (&$corpo) { $corpo = $evento->message->getTextBody(); });
    $id = app(CodigoDeAcesso::class)->solicitar($this->usuario->email, 'admin', '127.0.0.1', 'sessao-entrega');
    preg_match('/[0-9]{6}/', $corpo, $captura);
    expect($captura)->toHaveCount(1);
    expect(DB::table('codigos_de_acesso')->where('id', $id)->value('codigo_hash'))->not->toBe($captura[0]);
    expect(app(CodigoDeAcesso::class)->consumir($id, $captura[0], 'sessao-entrega')?->id)->toBe($this->usuario->id);
});

it('limita reenvio por email mesmo trocando de sessão', function () {
    $servico = app(CodigoDeAcesso::class);
    $servico->solicitar($this->usuario->email, 'admin', '127.0.0.1', 'sessao');
    expect(fn () => $servico->solicitar($this->usuario->email, 'admin', '127.0.0.2', 'outra'))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('operador acessa a gestão e cliente continua impedido', function () {
    $this->usuario->forceFill(['operador'=>true])->save();
    $id = inserirCodigoTeste($this->usuario, 'sessao-gestao', '123456', 'revenda');
    expect(app(CodigoDeAcesso::class)->consumir($id, '123456', 'sessao-gestao')?->id)->toBe($this->usuario->id);
    $this->actingAs($this->usuario)->get('https://'.config('app.revenda_domain').'/gestao/clientes')->assertOk();
});

it('mostra somente o token depois de solicitar e permite trocar email', function () {
    $this->withSession(['acesso.solicitado'=>true,'acesso.painel'=>'revenda','acesso.email'=>'acesso@example.test'])
        ->get('https://'.config('app.revenda_domain').'/gestao/login')->assertOk()
        ->assertSee('Token de acesso')->assertSee('aria-label="Número 6 do token"', false)
        ->assertDontSee('type="email"', false)->assertSee('dez minutos');
    $this->post('/acesso/trocar-email',['painel'=>'revenda'])->assertRedirect('/gestao/login');
    $this->get('https://'.config('app.revenda_domain').'/gestao/login')->assertSee('type="email"', false)->assertDontSee('id="codigo"', false);
});


it('preserva token anterior quando reenvio é bloqueado', function () {
    $this->get('/admin/login');
    $sessao = session()->getId();
    $id = inserirCodigoTeste($this->usuario, $sessao);
    RateLimiter::hit('codigo-envio:'.hash('sha256', $this->usuario->email), 60);
    $this->withCookie(config('session.cookie'), $sessao)
        ->withSession(['acesso.codigo_id'=>$id,'acesso.painel'=>'admin','acesso.solicitado'=>true])
        ->post('/acesso/solicitar', ['email'=>$this->usuario->email,'painel'=>'admin'])
        ->assertSessionHasErrors('email');
    expect(DB::table('codigos_de_acesso')->where('id',$id)->value('usado_em'))->toBeNull();
    $this->post('/acesso/confirmar',['codigo'=>'123456'])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($this->usuario);
});


it('exibe tempo restante real sem reiniciar ao recarregar', function () {
    $id = inserirCodigoTeste($this->usuario, 'sessao');
    DB::table('codigos_de_acesso')->where('id',$id)->update(['expira_em'=>now()->addMinutes(4)]);
    $this->withSession(['acesso.codigo_id'=>$id,'acesso.painel'=>'admin','acesso.solicitado'=>true])
        ->get('/admin/login')->assertOk()->assertSee('role="timer"', false)->assertSee('04:00');
    $this->travel(2)->minutes();
    $this->get('/admin/login')->assertOk()->assertSee('02:00');
    $this->travel(3)->minutes();
    $this->get('/admin/login')->assertOk()->assertSee('00:00');
});


it('envia email visual com alternativa em texto e token legível', function () {
    $mensagem = null;
    Illuminate\Support\Facades\Event::listen(Illuminate\Mail\Events\MessageSending::class, function ($evento) use (&$mensagem) { $mensagem = $evento->message; });
    app(CodigoDeAcesso::class)->solicitar($this->usuario->email,'admin','127.0.0.1','sessao-email');
    expect($mensagem->getHtmlBody())->toContain('Seu token chegou.', '#6338f6', '10 minutos');
    preg_match('/[0-9]{6}/',$mensagem->getTextBody(),$token);
    expect($token)->toHaveCount(1);
    expect($mensagem->getHtmlBody())->toContain($token[0]);
});


it('mostra o atalho de gestão somente ao operador', function () {
    $this->actingAs($this->usuario)->get('/admin')->assertOk()->assertDontSee('Gestão de contas');
    $this->usuario->forceFill(['operador'=>true])->save();
    $this->actingAs($this->usuario)->get('/admin')->assertOk()->assertSee('Gestão de contas');
});
