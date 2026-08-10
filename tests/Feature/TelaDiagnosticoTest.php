<?php

use App\Filament\Pages\Diagnostico as Tela;
use App\Models\{Tenant, User};
use App\Services\Diagnostico as Verificador;
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * A tela de saude do sistema.
 *
 * A verificacao existia so como comando de console. Isso serve para a madrugada, nao para a
 * pergunta que aparece no meio do atendimento: "esta lento, e o sistema?".
 */

/** Substitui o verificador por um que responde o que o teste quiser. */
function comProblemas(array $problemas): void
{
    app()->bind(Verificador::class, fn () => new class($problemas) extends Verificador
    {
        public function __construct(private array $achados)
        {
            parent::__construct();
        }

        public function verificar(): array
        {
            return $this->achados;
        }

        public function criticos(): array
        {
            return array_values(array_filter($this->achados, fn ($p) => $p['nivel'] === self::CRITICO));
        }
    });
}

beforeEach(function () {
    // O apontamento do webhook conferido agora mesmo. A verificacao entrou depois destes
    // testes e nao e o assunto deles: sem isto, cada cenario com canal Evolution carregaria
    // um critico a mais e as contagens de alerta parariam de fechar.
    App\Models\SystemSetting::gravar(
        App\Console\Commands\ConferirWebhooks::SELO,
        now()->toIso8601String(),
    );

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'diag']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Admin',
        'email' => 'admin@diag.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->atendente = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Atendente',
        'email' => 'at@diag.test', 'password' => 'segredo123',
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

it('mostra TUDO CERTO e diz quantas verificacoes passaram', function () {
    // "Tudo certo" sozinho nao tranquiliza: pode significar que nada foi verificado.
    comProblemas([]);

    Livewire::actingAs($this->admin)
        ->test(Tela::class)
        ->assertSee('Tudo certo')
        ->assertSee('verificações abaixo passaram');
});

it('lista tambem o que esta bem, e nao so o que quebrou', function () {
    comProblemas([['chave' => 'redis', 'nivel' => 'critico', 'mensagem' => 'Redis fora do ar']]);

    Livewire::actingAs($this->admin)
        ->test(Tela::class)
        ->assertSee('Redis fora do ar')          // o problema
        ->assertSee('Banco de dados acessível')  // e o que passou
        ->assertSee('Backup recente');
});

it('critico e aviso nao se confundem no veredito', function () {
    comProblemas([['chave' => 'whisper', 'nivel' => 'aviso', 'mensagem' => 'Whisper fora do ar']]);

    Livewire::actingAs($this->admin)
        ->test(Tela::class)
        ->assertSee('ponto(s) de atenção')
        ->assertDontSee('problema(s) crítico(s)');
});

it('a insignia do menu conta so os criticos', function () {
    // Aviso na insignia treina a ignorar insignia, e ai o dia do problema de verdade ela nao
    // e vista.
    comProblemas([
        ['chave' => 'redis', 'nivel' => 'critico', 'mensagem' => 'Redis fora do ar'],
        ['chave' => 'whisper', 'nivel' => 'aviso', 'mensagem' => 'Whisper fora do ar'],
    ]);

    expect(Tela::getNavigationBadge())->toBe('1');

    comProblemas([]);

    expect(Tela::getNavigationBadge())->toBeNull();
});

it('verificacao nova sem descricao ainda aparece', function () {
    // Verificacao acrescentada no servico nao pode ficar invisivel aqui so porque ninguem
    // lembrou de descreve-la.
    comProblemas([['chave' => 'coisa_nova', 'nivel' => 'critico', 'mensagem' => 'Algo que ninguem descreveu']]);

    Livewire::actingAs($this->admin)
        ->test(Tela::class)
        ->assertSee('Algo que ninguem descreveu');
});

it('toda verificacao do servico tem descricao na cobertura', function () {
    // Este teste existe porque o desencontro ACONTECEU: a cobertura dizia 'fila_acumulada' e
    // o servico usava 'fila'. O efeito era silencioso e enganoso — "Fila acumulando trabalho"
    // aparecia sempre verde, porque a chave nunca casava, e o problema real entrava na tela
    // como um item solto sem descricao.
    //
    // Encontrei com um script de uma vez. Script de uma vez nao impede voltar.
    $fonte = file_get_contents(app_path('Services/Diagnostico.php'));

    preg_match_all("/(?:problema|porta)\(\s*'([a-z_]+)'/", $fonte, $achadas);

    $usadas = array_values(array_unique($achadas[1]));
    $descritas = array_keys(Verificador::COBERTURA);

    expect(array_diff($usadas, $descritas))->toBe([], 'verificacao sem descricao na COBERTURA')
        ->and(array_diff($descritas, $usadas))->toBe([], 'descricao na COBERTURA sem verificacao correspondente');
});

it('atendente nao ve a tela', function () {
    expect(Tela::canAccess())->toBeFalse();

    $this->actingAs($this->atendente);

    expect(Tela::canAccess())->toBeFalse();

    $this->actingAs($this->admin);

    expect(Tela::canAccess())->toBeTrue();
});
