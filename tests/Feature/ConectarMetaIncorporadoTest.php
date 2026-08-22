<?php

use App\Models\{Channel, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

/*
 * Cadastro Incorporado (Embedded Signup): o botao "Continuar com o Facebook" troca o que
 * o popup devolveu (code + waba_id + phone_number_id) por um canal pronto, sem ninguem
 * copiar Phone Number ID, WABA ID ou token do painel da Meta.
 *
 * O que esta sob prova aqui NAO e o desenho da tela (isso e JS) — e que o servidor so cria
 * o canal quando as duas chamadas que nao podem faltar (trocar o code, inscrever o
 * webhook) deram certo. Um canal criado sem uma delas pareceria "aberto" e nao receberia
 * nada, calado — pior que nao existir.
 */

beforeEach(function () {
    config(['services.meta.versao' => 'v23.0', 'services.meta.app_id' => 'app-id-teste', 'services.meta.app_secret' => 'app-secret-teste']);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'incorporado']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@incorporado.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

function pedidoDeConexao(array $sobrescreve = []): array
{
    return array_merge([
        'code'            => 'codigo-do-popup',
        'waba_id'         => '3620023178150458',
        'phone_number_id' => '1235849066282498',
        'business_id'     => '9999999999',
    ], $sobrescreve);
}

it('cria o canal quando a troca do código e a inscrição do webhook dão certo', function () {
    // Especifico antes de generico, de proposito: um segundo fake mais generico nao
    // substitui o primeiro, e a ordem errada faria o teste passar pelo motivo errado.
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token-da-waba-do-cliente']),
        'graph.facebook.com/*/subscribed_apps*'   => Http::response(['success' => true]),
        'graph.facebook.com/*'                    => Http::response([
            'display_phone_number' => '+1 555-672-5603',
            'verified_name'        => 'Cliente Novo',
        ]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), pedidoDeConexao());

    $resposta->assertOk()->assertJson(['ok' => true]);
    expect($resposta->json('redirect'))->toBeString()->not->toBeEmpty();

    $canal = Channel::where('meta_waba_id', '3620023178150458')->firstOrFail();

    expect($canal->tipo)->toBe(Channel::META_CLOUD)
        ->and($canal->meta_phone_number_id)->toBe('1235849066282498')
        ->and($canal->meta_business_id)->toBe('9999999999')
        ->and($canal->meta_token)->toBe('EAA-token-da-waba-do-cliente')
        ->and($canal->telefone_e164)->toBe('+15556725603')
        ->and($canal->nome)->toBe('Cliente Novo')
        ->and($canal->status)->toBe('open')
        ->and($canal->conectado_em)->not->toBeNull();

    // Inscrever o webhook e a chamada que ja custou tempo de investigacao antes (ver
    // CLAUDE.md): sem provar que ela saiu, o teste nao provaria a parte que mais importa.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/3620023178150458/subscribed_apps')
        && $r->hasHeader('Authorization', 'Bearer EAA-token-da-waba-do-cliente'));
});

it('não cria canal quando a troca do código falha', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['message' => 'code invalido ou expirado']], 400),
        'graph.facebook.com/*'                     => Http::response(['success' => true]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), pedidoDeConexao());

    $resposta->assertStatus(422)->assertJson(['ok' => false]);
    expect($resposta->json('erro'))->toBeString()->not->toBeEmpty();

    expect(Channel::count())->toBe(0);

    // Sem token, nao ha o que inscrever: a segunda chamada nunca deveria ter saido.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'subscribed_apps'));
});

it('não cria canal quando a inscrição do webhook falha, mesmo com token válido', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token-valido']),
        'graph.facebook.com/*/subscribed_apps*'   => Http::response(['error' => ['message' => 'Permissions error']], 400),
        'graph.facebook.com/*'                    => Http::response(['success' => true]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), pedidoDeConexao());

    $resposta->assertStatus(422)->assertJson(['ok' => false]);

    // Um canal "aberto" que nao recebe nada e pior que nenhum canal: ninguem saberia
    // que precisa consertar, porque nao aparece erro nenhum na tela do cliente.
    expect(Channel::count())->toBe(0);
});

it('cria com nome provisório quando a Meta não devolve o nome verificado', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token']),
        'graph.facebook.com/*/subscribed_apps*'   => Http::response(['success' => true]),
        // Sem verified_name nem display_phone_number - a Meta as vezes so devolve o id.
        'graph.facebook.com/*'                    => Http::response(['id' => '1235849066282498']),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), pedidoDeConexao());

    $resposta->assertOk();

    $canal = Channel::where('meta_waba_id', '3620023178150458')->firstOrFail();

    expect($canal->nome)->toBe(Channel::NOME_PROVISORIO)
        ->and($canal->telefone_e164)->toBeNull()
        // a conexao em si nao falhou so por faltar o nome - isso e cosmetico
        ->and($canal->status)->toBe('open');
});

it('recusa quem não é admin', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin', 'email' => 'semadmin@incorporado.test',
        'password' => 'segredo123', 'admin' => false,
    ]);

    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'nao-deveria-chegar-aqui'])]);

    $resposta = $this->actingAs($semPermissao)
        ->postJson(route('canais.meta.conectar-incorporado'), pedidoDeConexao());

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

/*
 * Callback do link hospedado pela Meta (GET, nao POST).
 *
 * Existe porque o FB.login() classico com este config_id ficava preso na tela de
 * "continuar como Fulano" e nunca completava — o link hospedado prova que a configuracao
 * do lado da Meta esta certa, e volta por REDIRECT do navegador (query string), nao por
 * postMessage. A pagina de retorno roda dentro do popup e manda a aba principal
 * (window.opener) para o canal pronto, fechando o popup sozinho.
 */

it('cria o canal e devolve a página que fecha o popup, quando o retorno vem completo', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token-da-waba-do-cliente']),
        'graph.facebook.com/*/subscribed_apps*'    => Http::response(['success' => true]),
        'graph.facebook.com/*'                     => Http::response([
            'display_phone_number' => '+1 555-672-5603',
            'verified_name'        => 'Cliente Novo',
        ]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.meta.callback', pedidoDeConexao()));

    $resposta->assertOk()
        ->assertViewIs('filament.resources.channels.pages.conectar-meta-retorno')
        ->assertViewHas('sucesso', true);

    $canal = Channel::where('meta_waba_id', '3620023178150458')->firstOrFail();

    expect($canal->meta_token)->toBe('EAA-token-da-waba-do-cliente')
        ->and($canal->status)->toBe('open');

    // A pagina precisa mandar a aba principal (window.opener) para o canal, nao so o
    // popup — senao quem clicou fica olhando o resultado numa janela que tem de fechar
    // na mao.
    $resposta->assertSee('window.opener', false);
});

it('não cria canal e mostra o motivo quando falta o code no retorno', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'nao-deveria-chegar-aqui'])]);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.meta.callback', pedidoDeConexao(['code' => ''])));

    $resposta->assertStatus(422)->assertViewHas('sucesso', false);
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

it('não cria canal e mostra o motivo quando falta waba_id/phone_number_id no retorno', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'nao-deveria-chegar-aqui'])]);

    // E exatamente o que a Meta manda quando o assistente de escolher a WABA nao chega
    // a completar: so o code, sem os outros dois.
    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.meta.callback', ['code' => 'codigo-do-popup']));

    $resposta->assertStatus(422)->assertViewHas('sucesso', false);
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

it('recusa quem não é admin no retorno', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin 2', 'email' => 'semadmin2@incorporado.test',
        'password' => 'segredo123', 'admin' => false,
    ]);

    $resposta = $this->actingAs($semPermissao)
        ->get(route('canais.meta.callback', pedidoDeConexao()));

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
});

it('nunca mostra o code na página de erro do retorno', function () {
    // O code e credencial de uso unico, mas segredo em pagina de erro e log e o
    // tipo de coisa que sobrevive num print de tela pedido de suporte.
    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'nao-deveria-chegar-aqui'])]);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.meta.callback', ['code' => 'segredo-nao-pode-aparecer']));

    $resposta->assertStatus(422)
        ->assertDontSee('segredo-nao-pode-aparecer')
        ->assertSee('(presente)');
});

/*
 * Reconexao: token expirado/revogado de um canal que ja existe. Diferente de criar,
 * porque apagar e recriar perderia o id do canal e desconectaria o historico de
 * conversas dele — ver EditChannel::getHeaderActions() e ConectarMetaIncorporado::mount().
 */

it('reconecta um canal existente em vez de criar outro, quando há reconexão pendente na sessão', function () {
    $canal = Channel::create([
        'nome' => 'Comercial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => 'id-velho', 'meta_waba_id' => 'waba-velha',
        'meta_token' => 'EAA-token-vencido', 'telefone_e164' => '+5541999998888',
        'status' => 'close', 'ultimo_erro' => 'token expirado',
    ])->refresh();

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token-novo']),
        'graph.facebook.com/*/subscribed_apps*'    => Http::response(['success' => true]),
        'graph.facebook.com/*'                     => Http::response([
            'display_phone_number' => '+1 555-000-0000',
            'verified_name'        => 'Nome que a Meta devolveria',
        ]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->withSession(['meta_reconectar_canal_id' => $canal->id])
        ->get(route('canais.meta.callback', pedidoDeConexao(['waba_id' => 'waba-nova', 'phone_number_id' => 'id-novo'])));

    $resposta->assertOk()->assertViewHas('sucesso', true);

    // UM canal so: reconectar nao pode duplicar.
    expect(Channel::count())->toBe(1);

    $canal->refresh();
    expect($canal->meta_waba_id)->toBe('waba-nova')
        ->and($canal->meta_phone_number_id)->toBe('id-novo')
        ->and($canal->meta_token)->toBe('EAA-token-novo')
        ->and($canal->status)->toBe('open')
        ->and($canal->ultimo_erro)->toBeNull()
        // nome e numero sao do canal que ja existia, escolhidos por quem usa o
        // sistema — reconectar nao pode pisar num nome que a pessoa ja personalizou.
        ->and($canal->nome)->toBe('Comercial')
        ->and($canal->telefone_e164)->toBe('+5541999998888');

    // A sessao nao pode sobrar: a proxima conexao (de um canal diferente) tem de
    // ser tratada como criacao, nao reconexao deste canal de novo.
    expect(session('meta_reconectar_canal_id'))->toBeNull();
});

it('a tela de conectar marca a sessão e muda o título quando chamada com ?canal=', function () {
    $canal = Channel::create([
        'nome' => 'Comercial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => 'id', 'meta_waba_id' => 'waba',
    ])->refresh();

    $resposta = $this->actingAs($this->admin)
        ->get(\App\Filament\Resources\Channels\ChannelResource::getUrl('conectar-meta').'?canal='.$canal->id);

    $resposta->assertOk()->assertSee('Reconectar pelo Facebook');
    expect(session('meta_reconectar_canal_id'))->toBe($canal->id);
});

it('a tela de conectar não marca reconexão para canal de outro tenant', function () {
    $outroTenant = Tenant::create(['nome' => 'Outro', 'slug' => 'outro-tenant']);
    $canalAlheio = Channel::withoutGlobalScopes()->create([
        'tenant_id' => $outroTenant->id, 'nome' => 'Nao e meu', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => 'id', 'meta_waba_id' => 'waba',
    ]);

    $resposta = $this->actingAs($this->admin)
        ->get(\App\Filament\Resources\Channels\ChannelResource::getUrl('conectar-meta').'?canal='.$canalAlheio->id);

    $resposta->assertOk()->assertSee('Conectar pelo Facebook')->assertDontSee('Reconectar pelo Facebook');
    expect(session('meta_reconectar_canal_id'))->toBeNull();
});
