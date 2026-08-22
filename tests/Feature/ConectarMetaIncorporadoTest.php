<?php

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\{Channel, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Cadastro Incorporado (Embedded Signup): o botao "Continuar com o Facebook" troca o que
 * o popup devolveu por um canal pronto, sem ninguem copiar Phone Number ID, WABA ID ou
 * token do painel da Meta.
 *
 * O fluxo tem DOIS passos que nao podem confiar na mesma sessao (ver o comentario em
 * MetaEmbeddedSignupController): `retorno` (GET, dentro do popup) fala com a Meta e
 * guarda o resultado num cache de curta duracao; `conectar` (POST, na aba principal)
 * persiste no banco, usando o id desse cache. Sessao NUNCA decide para qual canal e a
 * reconexao — quem decide e o JS da aba que abriu o popup.
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

function pedidoDeRetorno(array $sobrescreve = []): array
{
    return array_merge([
        'code'            => 'codigo-do-popup',
        'waba_id'         => '3620023178150458',
        'phone_number_id' => '1235849066282498',
        'business_id'     => '9999999999',
    ], $sobrescreve);
}

/*
 * `retorno` (GET): fala com a Meta, mas NUNCA cria/atualiza canal — so guarda o
 * resultado no cache. Quem persiste e sempre o `conectar` (POST).
 */

it('guarda o resultado no cache e devolve a página que fecha o popup, quando o retorno vem completo', function () {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'EAA-token-da-waba-do-cliente']),
        'graph.facebook.com/*/subscribed_apps*'    => Http::response(['success' => true]),
        'graph.facebook.com/*'                     => Http::response([
            'display_phone_number' => '+1 555-672-5603',
            'verified_name'        => 'Cliente Novo',
        ]),
    ]);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.meta.callback', pedidoDeRetorno()));

    $resposta->assertOk()
        ->assertViewIs('filament.resources.channels.pages.conectar-meta-retorno')
        ->assertViewHas('sucesso', true);

    // Nenhum canal criado aqui — so o conectar (POST) persiste.
    expect(Channel::count())->toBe(0);

    $signupToken = $resposta->viewData('signupToken');
    expect($signupToken)->toBeString()->not->toBeEmpty();

    $pendente = Cache::get("meta_signup:{$signupToken}");
    expect($pendente)->not->toBeNull()
        ->and($pendente['waba_id'])->toBe('3620023178150458')
        ->and($pendente['phone_number_id'])->toBe('1235849066282498')
        ->and($pendente['token'])->toBe('EAA-token-da-waba-do-cliente')
        ->and($pendente['nome'])->toBe('Cliente Novo')
        ->and($pendente['tenant_id'])->toBe($this->tenant->id);

    // Inscrever o webhook e a chamada que ja custou tempo de investigacao antes (ver
    // CLAUDE.md): sem provar que ela saiu, o teste nao provaria a parte que mais importa.
    Http::assertSent(fn ($r) => str_contains($r->url(), '/3620023178150458/subscribed_apps')
        && $r->hasHeader('Authorization', 'Bearer EAA-token-da-waba-do-cliente'));

    // A pagina precisa mandar a aba principal (window.opener) fechar o popup sozinho —
    // senao quem clicou fica olhando o resultado numa janela que tem de fechar na mao.
    $resposta->assertSee('window.opener', false)
        ->assertDontSee('EAA-token-da-waba-do-cliente'); // o token nunca atravessa o navegador
});

it('não guarda nada no cache e mostra o motivo quando falta o code no retorno', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'nao-deveria-chegar-aqui'])]);

    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.meta.callback', pedidoDeRetorno(['code' => ''])));

    $resposta->assertStatus(422)->assertViewHas('sucesso', false);
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

it('não guarda nada no cache e mostra o motivo quando falta waba_id/phone_number_id no retorno', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'nao-deveria-chegar-aqui'])]);

    // E exatamente o que a Meta manda quando o assistente de escolher a WABA nao chega
    // a completar: so o code, sem os outros dois.
    $resposta = $this->actingAs($this->admin)
        ->get(route('canais.meta.callback', ['code' => 'codigo-do-popup']));

    $resposta->assertStatus(422)->assertViewHas('sucesso', false);
    expect(Channel::count())->toBe(0);
    Http::assertNothingSent();
});

it('não fala com a Meta nem guarda nada quando quem chama o retorno não é admin', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin', 'email' => 'semadmin@incorporado.test',
        'password' => 'segredo123', 'admin' => false,
    ]);

    $resposta = $this->actingAs($semPermissao)
        ->get(route('canais.meta.callback', pedidoDeRetorno()));

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
 * `conectar` (POST): quem persiste. So aceita um signup_token que passou pelo
 * `retorno` — nao aceita code/waba_id/etc direto, porque quem fala com a Meta e
 * sempre o retorno.
 */

function guardarPendente(array $sobrescreve = []): string
{
    $token = (string) Illuminate\Support\Str::uuid();

    Cache::put("meta_signup:{$token}", array_merge([
        'waba_id'         => '3620023178150458',
        'phone_number_id' => '1235849066282498',
        'business_id'     => '9999999999',
        'token'           => 'EAA-token-da-waba-do-cliente',
        'nome'            => 'Cliente Novo',
        'numero'          => '+15556725603',
        'tenant_id'       => Tenant::first()?->id,
    ], $sobrescreve), now()->addMinutes(5));

    return $token;
}

it('cria o canal quando o signup_token é válido', function () {
    $token = guardarPendente(['tenant_id' => $this->tenant->id]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), ['signup_token' => $token]);

    $resposta->assertOk()->assertJson(['ok' => true]);
    expect($resposta->json('redirect'))->toBeString()->not->toBeEmpty();

    $canal = Channel::where('meta_waba_id', '3620023178150458')->firstOrFail();
    expect($canal->tipo)->toBe(Channel::META_CLOUD)
        ->and($canal->meta_phone_number_id)->toBe('1235849066282498')
        ->and($canal->meta_business_id)->toBe('9999999999')
        ->and($canal->meta_token)->toBe('EAA-token-da-waba-do-cliente')
        ->and($canal->telefone_e164)->toBe('+15556725603')
        ->and($canal->nome)->toBe('Cliente Novo')
        ->and($canal->status)->toBe('open');

    // pull: o mesmo signup_token nao pode servir duas vezes.
    expect(Cache::get("meta_signup:{$token}"))->toBeNull();
});

it('reconecta um canal existente em vez de criar outro, quando canal_id é informado', function () {
    $canal = Channel::create([
        'nome' => 'Comercial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => 'id-velho', 'meta_waba_id' => 'waba-velha',
        'meta_token' => 'EAA-token-vencido', 'telefone_e164' => '+5541999998888',
        'status' => 'close', 'ultimo_erro' => 'token expirado',
    ])->refresh();

    $token = guardarPendente([
        'tenant_id' => $this->tenant->id,
        'waba_id' => 'waba-nova', 'phone_number_id' => 'id-novo', 'token' => 'EAA-token-novo',
    ]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), ['signup_token' => $token, 'canal_id' => $canal->id]);

    $resposta->assertOk()->assertJson(['ok' => true]);

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
});

it('recusa signup_token inexistente ou expirado, sem criar canal', function () {
    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), ['signup_token' => 'token-que-nunca-existiu']);

    $resposta->assertStatus(422)->assertJson(['ok' => false]);
    expect(Channel::count())->toBe(0);
});

it('recusa signup_token de outra conta, sem criar canal', function () {
    $outroTenant = Tenant::create(['nome' => 'Outro', 'slug' => 'outro-tenant-conectar']);
    $token = guardarPendente(['tenant_id' => $outroTenant->id]);

    $resposta = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), ['signup_token' => $token]);

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
    // O token pertencia a outra conta, mas ja foi consumido (pull) — nao pode
    // sobrar disponivel para uma segunda tentativa adivinhar.
    expect(Cache::get("meta_signup:{$token}"))->toBeNull();
});

it('recusa reutilizar o mesmo signup_token uma segunda vez', function () {
    $token = guardarPendente(['tenant_id' => $this->tenant->id]);

    $primeira = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), ['signup_token' => $token]);
    $primeira->assertOk();

    $segunda = $this->actingAs($this->admin)
        ->postJson(route('canais.meta.conectar-incorporado'), ['signup_token' => $token]);

    $segunda->assertStatus(422)->assertJson(['ok' => false]);
    // A primeira chamada criou UM canal; a segunda nao pode criar outro.
    expect(Channel::count())->toBe(1);
});

it('recusa quem não é admin no conectar', function () {
    $semPermissao = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Sem admin 2', 'email' => 'semadmin2@incorporado.test',
        'password' => 'segredo123', 'admin' => false,
    ]);
    $token = guardarPendente(['tenant_id' => $this->tenant->id]);

    $resposta = $this->actingAs($semPermissao)
        ->postJson(route('canais.meta.conectar-incorporado'), ['signup_token' => $token]);

    $resposta->assertForbidden();
    expect(Channel::count())->toBe(0);
    // Nem sequer chegou a consumir o token — quem nao e admin nem tenta.
    expect(Cache::get("meta_signup:{$token}"))->not->toBeNull();
});

/*
 * A tela "Continuar com o Facebook" (ConectarMetaIncorporado): quem decide se e
 * criacao ou reconexao e o ?canal= da URL, exposto ao JS como constante da aba —
 * nunca sessao.
 */

it('a tela de conectar expõe o canalId ao JS e muda o título quando chamada com ?canal=', function () {
    $canal = Channel::create([
        'nome' => 'Comercial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => 'id', 'meta_waba_id' => 'waba',
    ])->refresh();

    $resposta = $this->actingAs($this->admin)
        ->get(ChannelResource::getUrl('conectar-meta').'?canal='.$canal->id);

    $resposta->assertOk()
        ->assertSee('Reconectar pelo Facebook')
        ->assertSee("const canalId = {$canal->id};", false);
});

it('a tela de conectar não marca reconexão para canal de outro tenant', function () {
    $outroTenant = Tenant::create(['nome' => 'Outro', 'slug' => 'outro-tenant']);
    $canalAlheio = Channel::withoutGlobalScopes()->create([
        'tenant_id' => $outroTenant->id, 'nome' => 'Nao e meu', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => 'id', 'meta_waba_id' => 'waba',
    ]);

    $resposta = $this->actingAs($this->admin)
        ->get(ChannelResource::getUrl('conectar-meta').'?canal='.$canalAlheio->id);

    $resposta->assertOk()
        ->assertSee('Conectar pelo Facebook')
        ->assertDontSee('Reconectar pelo Facebook')
        ->assertSee('const canalId = null;', false);
});
