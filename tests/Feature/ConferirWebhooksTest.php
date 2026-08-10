<?php

use App\Console\Commands\ConferirWebhooks;
use App\Models\{Channel, SystemSetting, Tenant};
use App\Services\Diagnostico;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/*
 * PARA ONDE A EVOLUTION AVISA.
 *
 * O ENDERECO NAO MORA AQUI. Ele mora dentro da Evolution, gravado uma unica vez quando o canal
 * nasceu. O painel mudou de dominio e ela continuou avisando no endereco antigo — que passou a
 * responder um redirecionamento. Ela nao segue redirecionamento: POSTa, recebe 302, considera
 * entregue. Dois dias de mensagem de cliente cairam nesse buraco e, do lado de dentro, nada
 * parecia errado — nenhum erro, nenhuma fila, nenhuma falha. So silencio.
 *
 * O VIGIA QUE EXISTIA OLHAVA A COISA ERRADA: contava aviso que CHEGOU e nao foi processado, ou
 * seja, cano entupido. Cano desligado da zero, e zero para ele era saude.
 *
 * ENTAO O DIAGNOSTICO PASSOU A COBRAR PROVA em vez de olhar sintoma: o selo abaixo so e gravado
 * quando todos os canais foram conferidos sem erro. Selo velho acusa as duas doencas de uma vez
 * — apontamento errado que nao pudemos corrigir, e o proprio verificador morto.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'webhook']);
    TenantContext::set($this->conta->id);

    $this->canal = Channel::create([
        'tenant_id'      => $this->conta->id,
        'tipo'           => Channel::EVOLUTION,
        'nome'           => 'Zap',
        'instance_name'  => 't1-c9',
        'status'         => 'open',
        'webhook_secret' => 'segredinho',
    ]);

    $this->esperado = $this->canal->webhookUrl();
});

afterEach(fn () => TenantContext::forget());

/*
 * UM fake por teste, com closure que decide pela URL. Http::fake chamado uma segunda vez NAO
 * substitui o primeiro — armadilha que ja custou seis rodadas de depuracao neste projeto.
 */
function fingirEvolution(string $urlRegistrada): void
{
    Http::fake(['*' => function ($pedido) use ($urlRegistrada) {
        if (str_contains($pedido->url(), '/webhook/find/')) {
            return Http::response($urlRegistrada === '' ? [] : ['url' => $urlRegistrada, 'enabled' => true]);
        }

        return Http::response(['url' => $pedido['webhook']['url'] ?? null], 200);
    }]);
}

it('reaponta o canal que avisa no dominio antigo', function () {
    fingirEvolution('https://chat.onsight.com.br/webhooks/evolution/'.$this->canal->id.'/segredinho');

    $this->artisan('onchat:conferir-webhooks')->assertSuccessful();

    // Ele mandou a Evolution avisar aqui, com o endereco que o proprio canal calcula.
    Http::assertSent(fn ($p) => str_contains($p->url(), '/webhook/set/t1-c9')
        && $p['webhook']['url'] === $this->esperado
        && $p['webhook']['enabled'] === true);
});

it('reaponta tambem quem nao avisa em lugar nenhum', function () {
    // Instancia recriada a mao, ou restaurada de backup, chega sem webhook: silencio igual.
    fingirEvolution('');

    $this->artisan('onchat:conferir-webhooks')->assertSuccessful();

    Http::assertSent(fn ($p) => str_contains($p->url(), '/webhook/set/t1-c9'));
});

it('nao mexe em quem ja aponta para o lugar certo', function () {
    fingirEvolution($this->esperado);

    $this->artisan('onchat:conferir-webhooks')->assertSuccessful();

    Http::assertNotSent(fn ($p) => str_contains($p->url(), '/webhook/set/'));
});

it('reaponta pedindo os mesmos eventos que criaram o canal', function () {
    // Reapontar com lista menor apagaria em silencio um tipo de aviso que o sistema depende
    // de receber — e a falta so apareceria como funcionalidade morta, dias depois.
    fingirEvolution('https://outro.lugar/webhooks/evolution/1/x');

    $this->artisan('onchat:conferir-webhooks')->assertSuccessful();

    Http::assertSent(function ($p) {
        if (! str_contains($p->url(), '/webhook/set/')) {
            return false;
        }

        return $p['webhook']['events'] === ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'SEND_MESSAGE'];
    });
});

it('so conferir relata a diferenca sem corrigir', function () {
    fingirEvolution('https://chat.onsight.com.br/webhooks/evolution/1/x');

    $this->artisan('onchat:conferir-webhooks --so-conferir')->assertFailed();

    Http::assertNotSent(fn ($p) => str_contains($p->url(), '/webhook/set/'));
});

// ------------------------------------------------------------------------ o selo

it('o selo e gravado quando tudo foi conferido', function () {
    fingirEvolution($this->esperado);

    $this->artisan('onchat:conferir-webhooks')->assertSuccessful();

    expect(SystemSetting::ler(ConferirWebhooks::SELO))->not->toBeNull();
});

it('o selo NAO e gravado quando a conferencia falhou', function () {
    /*
     * O centro de tudo. Gravar o selo mesmo com erro seria pior que nao gravar: o diagnostico
     * passaria a jurar que o apontamento esta conferido justamente quando ninguem conseguiu
     * conferir — silencio com aparencia de saude, que e a doenca original deste episodio.
     */
    Http::fake(['*' => Http::response(['erro' => 'sem apikey'], 401)]);

    $this->artisan('onchat:conferir-webhooks')->assertFailed();

    expect(SystemSetting::ler(ConferirWebhooks::SELO))->toBeNull();
});

// ------------------------------------------------------- o diagnostico cobra a prova

it('o diagnostico acende quando ninguem conferiu ainda', function () {
    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn(['m']));

    $problemas = (new Diagnostico(fn () => true))->verificar();

    $apontamento = collect($problemas)->firstWhere('chave', 'webhook_apontamento');

    expect($apontamento)->not->toBeNull()
        ->and($apontamento['nivel'])->toBe(Diagnostico::CRITICO);
});

it('o diagnostico acende quando o verificador parou de rodar', function () {
    // Selo velho: seja porque o apontamento esta errado e nao pudemos corrigir, seja porque o
    // proprio comando morreu. As duas doencas acendem a mesma luz, de proposito.
    SystemSetting::gravar(ConferirWebhooks::SELO, now()->subHours(3)->toIso8601String());

    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn(['m']));

    expect(collect((new Diagnostico(fn () => true))->verificar())->firstWhere('chave', 'webhook_apontamento'))
        ->not->toBeNull();
});

it('o diagnostico cala quando a conferencia esta fresca', function () {
    SystemSetting::gravar(ConferirWebhooks::SELO, now()->toIso8601String());

    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn(['m']));

    expect(collect((new Diagnostico(fn () => true))->verificar())->firstWhere('chave', 'webhook_apontamento'))
        ->toBeNull();
});

it('servidor sem canal Evolution nao ganha alerta de apontamento', function () {
    // Alerta que acende sempre e alerta que se aprende a ignorar.
    $this->canal->delete();

    $this->mock(MasterSupervisorRepository::class, fn ($m) => $m->shouldReceive('all')->andReturn(['m']));

    expect(collect((new Diagnostico(fn () => true))->verificar())->firstWhere('chave', 'webhook_apontamento'))
        ->toBeNull();
});
