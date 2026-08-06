<?php

use App\Filament\Pages\{RelatorioPorAnuncio, Relatorios};
use App\Models\{Channel, Contact, Conversation, Message, Tag, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * Conversas por anuncio, e a planilha do recorte.
 *
 * Os dois existem pela mesma razao: o dado ja esta no banco e nao havia como ler. E os dois
 * carregam a mesma preocupacao — nao deixar a tela sugerir mais do que ela sabe.
 */

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'rel']);
    TenantContext::set($this->tenant->id);

    $this->admin = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Admin',
        'email' => 'admin@rel.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create(['nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111'])->refresh();

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

/** Conversa vinda (ou nao) de anuncio. */
function conversaDeAnuncio(?string $anuncio, ?string $titulo = null, ?int $diasAtras = 0): Conversation
{
    $contato = Contact::create(['nome' => 'C'.uniqid(), 'telefone_e164' => '+55419'.random_int(10000000, 99999999)]);

    $c = Conversation::create(array_filter([
        'channel_id'  => test()->canal->id,
        'contact_id'  => $contato->id,
        'origem_tipo' => $anuncio ? 'ad' : null,
        'origem_id'   => $anuncio,
        'origem'      => $anuncio ? ['titulo' => $titulo ?: 'Anúncio '.$anuncio] : null,
    ]));

    if ($diasAtras > 0) {
        $c->forceFill(['created_at' => now()->subDays($diasAtras)])->save();
    }

    return $c->refresh();
}

function csvDe($resposta): string
{
    ob_start();
    $resposta->sendContent();

    return (string) ob_get_clean();
}

// ============================================================== por anuncio

it('agrupa as conversas por anuncio e mostra o titulo', function () {
    conversaDeAnuncio('120210001', 'Internet fibra 500 MB');
    conversaDeAnuncio('120210001', 'Internet fibra 500 MB');
    conversaDeAnuncio('120210002', 'Combo com TV');

    Livewire::actingAs($this->admin)
        ->test(RelatorioPorAnuncio::class)
        ->assertSee('Internet fibra 500 MB')
        ->assertSee('Combo com TV')
        ->assertSee('120210001');
});

it('diz quantas conversas NAO vieram de anuncio', function () {
    // Sem esse numero a tela exageraria o peso dos anuncios, e a decisao de orcamento sai
    // torta: na maioria dos negocios o anuncio e a porta menor.
    conversaDeAnuncio('120210001');
    conversaDeAnuncio(null);
    conversaDeAnuncio(null);
    conversaDeAnuncio(null);

    $dados = Livewire::actingAs($this->admin)->test(RelatorioPorAnuncio::class)->viewData('outras');

    expect($dados)->toBe(3);
});

it('o periodo recorta de verdade', function () {
    conversaDeAnuncio('120210001', 'Recente');
    conversaDeAnuncio('120210009', 'Antigo', 60);

    $tela = Livewire::actingAs($this->admin)->test(RelatorioPorAnuncio::class);

    // 30 dias: so o recente
    $tela->assertSee('Recente')->assertDontSee('Antigo');

    // 90 dias: os dois
    $tela->call('periodo', 90)->assertSee('Recente')->assertSee('Antigo');
});

it('periodo invalido cai no padrao em vez de aceitar qualquer numero', function () {
    $tela = Livewire::actingAs($this->admin)->test(RelatorioPorAnuncio::class)->call('periodo', 9999);

    expect($tela->get('dias'))->toBe(30);
});

it('sem anuncio nenhum, explica que a tela se enche sozinha', function () {
    // Tela vazia sem explicacao parece defeito. Aqui nao ha nada para configurar.
    conversaDeAnuncio(null);

    Livewire::actingAs($this->admin)
        ->test(RelatorioPorAnuncio::class)
        ->assertSee('Nenhuma conversa veio de anúncio neste período')
        ->assertSee('Nada para configurar aqui');
});

it('atendente nao ve o relatorio', function () {
    expect(RelatorioPorAnuncio::canAccess())->toBeFalse();
});

// ===================================================================== o CSV

it('exporta as conversas com o recorte que esta na tela, e nao a base inteira', function () {
    // Exportar tudo quando a tela mostra 7 dias faria o gestor comparar planilha com tela e
    // concluir que um dos dois esta errado.
    $etiqueta = Tag::create(['nome' => 'Financeiro', 'cor' => 'emerald']);

    $comEtiqueta = conversaDeAnuncio('120210001', 'Fibra');
    $comEtiqueta->contact->tags()->attach($etiqueta->id);

    conversaDeAnuncio(null); // sem etiqueta: nao deve entrar

    $tela = Livewire::actingAs($this->admin)->test(Relatorios::class)->set('etiqueta', (string) $etiqueta->id);

    $csv = csvDe($tela->instance()->exportar());

    expect($csv)->toContain($comEtiqueta->contact->nome)
        ->and(substr_count($csv, "\n"))->toBe(2); // cabecalho + uma conversa
});

it('a planilha abre certo no Excel em portugues', function () {
    // BOM e ponto e virgula. Sem os dois, quem recebe conclui que o sistema exportou errado:
    // acento virado e tudo numa coluna so.
    conversaDeAnuncio('120210001', 'Fibra');

    $csv = csvDe(Livewire::actingAs($this->admin)->test(Relatorios::class)->instance()->exportar());

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        // fputcsv do PHP poe aspas em campo com ESPACO, nao so quando ha separador. O
        // cabecalho sai ID;"Aberta em";Contato — e foi o teste que estava errado, nao o
        // codigo.
        ->and($csv)->toContain('ID;"Aberta em";Contato');
});

it('a planilha leva a origem da conversa', function () {
    conversaDeAnuncio('120210001', 'Internet fibra 500 MB');

    $csv = csvDe(Livewire::actingAs($this->admin)->test(Relatorios::class)->instance()->exportar());

    expect($csv)->toContain('Internet fibra 500 MB')
        ->and($csv)->toContain('120210001');
});

it('o nome do arquivo carrega o recorte', function () {
    // Planilha baixada como "relatorio.csv" vira arquivo que ninguem sabe de que periodo era.
    $tela = Livewire::actingAs($this->admin)->test(Relatorios::class)->call('periodo', 7);

    $nome = $tela->instance()->exportar()->headers->get('content-disposition');

    expect($nome)->toContain('conversas-7d');
});
