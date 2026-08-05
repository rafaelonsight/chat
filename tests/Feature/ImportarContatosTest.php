<?php

use App\Filament\Imports\ContactImporter;
use App\Models\{Contact, ContactField, ContactFieldValue, Tag, Tenant, User};
use App\Support\TenantContext;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'imp']);
    TenantContext::set($this->tenant->id);

    $this->user = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Gestor',
        'email' => 'g@imp.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->import = Import::create([
        'user_id' => $this->user->id,
        'file_name' => 'contatos.csv',
        'file_path' => 'imports/contatos.csv',
        'importer' => ContactImporter::class,
        'total_rows' => 1,
        'processed_rows' => 0,
        'successful_rows' => 0,
    ]);
});

afterEach(fn () => TenantContext::forget());

/** Roda uma linha pelo importador, como o job faria. */
function importa(array $linha, array $opcoes = []): ContactImporter
{
    $colunas = array_keys($linha);

    $imp = new ContactImporter(
        import: test()->import,
        columnMap: array_combine($colunas, $colunas),
        options: $opcoes,
    );

    $imp(collect($linha)->all());

    return $imp;
}

it('importa um contato normalizando o telefone', function () {
    // A planilha traz do jeito que a pessoa digitou.
    importa(['telefone_e164' => '(84) 99614-3373', 'nome' => 'Maria Souza']);

    $c = Contact::where('nome', 'Maria Souza')->first();

    expect($c)->not->toBeNull()
        ->and($c->telefone_e164)->toBe('+5584996143373')
        // o jid sai do telefone sozinho, senao o contato nao existe no WhatsApp
        ->and($c->jid)->toContain('5584996143373');
});

it('o mesmo telefone em formatos diferentes e o MESMO contato', function () {
    // Sem normalizar antes de comparar, a deduplicacao nao acha nada e a base
    // duplica — e duplicata de contato quebra o historico da conversa.
    importa(['telefone_e164' => '84996143373', 'nome' => 'Maria']);
    importa(['telefone_e164' => '+55 84 99614-3373', 'nome' => 'Maria Souza']);

    expect(Contact::count())->toBe(1)
        ->and(Contact::first()->nome)->toBe('Maria Souza');
});

it('celula vazia NAO apaga o que ja estava preenchido', function () {
    // Planilha quase sempre vem com colunas incompletas. Importar nao pode ser um
    // jeito de limpar cadastro sem querer.
    importa(['telefone_e164' => '84996143373', 'nome' => 'Maria', 'email' => 'maria@x.com']);
    importa(['telefone_e164' => '84996143373', 'nome' => 'Maria Souza', 'email' => '']);

    $c = Contact::first();

    expect($c->nome)->toBe('Maria Souza')
        ->and($c->email)->toBe('maria@x.com');
});

it('telefone invalido recusa a LINHA com motivo legivel', function () {
    // Contato sem telefone nao serve para nada num chat: melhor a linha aparecer na
    // planilha de rejeitadas do que virar cadastro inutil.
    expect(fn () => importa(['telefone_e164' => 'nao tenho', 'nome' => 'Fulano']))
        ->toThrow(RowImportFailedException::class, 'Telefone inválido');

    expect(Contact::count())->toBe(0);
});

it('telefone em branco tambem recusa', function () {
    expect(fn () => importa(['telefone_e164' => '', 'nome' => 'Fulano']))
        ->toThrow(RowImportFailedException::class);
});

it('com a politica ignorar, o duplicado e listado como nao importado', function () {
    importa(['telefone_e164' => '84996143373', 'nome' => 'Maria']);

    expect(fn () => importa(
        ['telefone_e164' => '84996143373', 'nome' => 'Outra'],
        ['duplicados' => 'ignorar'],
    ))->toThrow(RowImportFailedException::class, 'Já existe contato');

    expect(Contact::first()->nome)->toBe('Maria');
});

it('preenche campo personalizado pela coluna da planilha', function () {
    $cpf = ContactField::create(['nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ, 'ordem' => 1]);

    importa(['telefone_e164' => '84996143373', 'nome' => 'Maria', 'campo_'.$cpf->id => '044.018.549-18']);

    $valor = ContactFieldValue::where('contact_field_id', $cpf->id)->value('valor');

    // Guardado normalizado, igual ao chatbot: mascara e coisa de exibicao.
    expect($valor)->toBe('04401854918');
});

it('campo personalizado invalido recusa a linha ANTES de gravar o contato', function () {
    // Recusar depois deixaria o contato gravado e o campo nao, e ninguem saberia
    // qual metade entrou. A critica e a MESMA do chatbot.
    $cpf = ContactField::create(['nome' => 'CPF', 'tipo' => ContactField::CPF_CNPJ, 'ordem' => 1]);

    expect(fn () => importa([
        'telefone_e164' => '84996143373',
        'nome' => 'Maria',
        'campo_'.$cpf->id => '111.111.111-11',
    ]))->toThrow(RowImportFailedException::class, 'CPF');

    expect(Contact::count())->toBe(0)
        ->and(ContactFieldValue::count())->toBe(0);
});

it('aplica as etiquetas escolhidas, com origem de importacao', function () {
    // A origem aparece no painel do contato: "Aplicada na importação". Sem ela, o
    // atendente nao sabe de onde veio aquela etiqueta.
    $tag = Tag::create(['nome' => 'Lote agosto', 'cor' => 'azul']);

    importa(
        ['telefone_e164' => '84996143373', 'nome' => 'Maria'],
        ['etiquetas' => [$tag->id]],
    );

    $c = Contact::first();
    $posta = $c->tags->first();

    expect($posta->nome)->toBe('Lote agosto')
        ->and($posta->pivot->origem)->toBe('importacao')
        ->and($posta->pivot->aplicado_por)->toBe($this->user->id);
});

it('as colunas oferecidas incluem os campos personalizados existentes', function () {
    // Quem criou "Contrato" em Configuracoes precisa poder mapear a coluna. Sem
    // isto, teria de preencher contato por contato.
    ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO, 'ordem' => 1]);

    $nomes = collect(ContactImporter::getColumns())->map(fn ($c) => $c->getName())->all();

    expect($nomes)->toContain('telefone_e164')
        ->and($nomes)->toContain('nome')
        ->and(collect($nomes)->filter(fn ($n) => str_starts_with($n, 'campo_')))->toHaveCount(1);
});

it('o telefone e a unica coluna de mapeamento obrigatorio', function () {
    $obrigatorias = collect(ContactImporter::getColumns())
        ->filter(fn ($c) => $c->isMappingRequired())
        ->map(fn ($c) => $c->getName())
        ->values()
        ->all();

    expect($obrigatorias)->toBe(['telefone_e164']);
});

it('o importador roda sem usuario logado, como no job de fila', function () {
    // O TenantContext cai para auth()->user() quando ninguem seta. Em job de fila
    // nao ha usuario: sem a linha que seta o tenant, o contato nasceria sem conta.
    TenantContext::forget();
    auth()->logout();

    importa(['telefone_e164' => '84996143373', 'nome' => 'Maria']);

    $c = Contact::withoutGlobalScope('tenant')->first();

    expect($c->tenant_id)->toBe($this->tenant->id);
});

// ============================================ A PLANILHA INTEIRA, DE PONTA A PONTA

it('a planilha de verdade atravessa: cabecalho reconhecido, boas importadas, ruins listadas', function () {
    // Testar linha a linha nao prova que o arquivo atravessa. Aqui vai o caminho
    // completo: CSV com cabecalho em portugues, o Filament reconhecendo as colunas
    // pelos apelidos, o job rodando, e a contagem no fim.
    \Illuminate\Support\Facades\Storage::fake('local');

    // O id NAO e 1: sequencia do Postgres nao volta atras quando a transacao do
    // teste e revertida, entao fixar 'campo_1' no mapa daria coluna inexistente.
    $contrato = ContactField::create(['nome' => 'Contrato', 'tipo' => ContactField::TEXTO_CURTO, 'ordem' => 1]);
    $tag = Tag::create(['nome' => 'Lote agosto', 'cor' => 'azul']);

    // Ponto e virgula: e o que o Excel em portugues gera, e o que a nossa exportacao
    // produz. Se a importacao nao lesse isso, o proprio ciclo exportar/importar
    // quebraria.
    $csv = <<<CSV
    nome;telefone;email;cidade;Contrato
    Maria Souza;(84) 99614-3373;maria@exemplo.com;Natal;778899
    João Lima;84 98888-7777;;Parnamirim;
    Sem Telefone;;ninguem@exemplo.com;Natal;
    Zé Repetido;+55 84 99614-3373;ze@exemplo.com;Natal;
    CSV;

    $csv = implode("\n", array_map('trim', explode("\n", trim($csv))));

    $caminho = 'imports/contatos.csv';
    \Illuminate\Support\Facades\Storage::disk('local')->put($caminho, $csv);

    $import = Import::create([
        'user_id' => $this->user->id,
        'file_name' => 'contatos.csv',
        'file_path' => $caminho,
        'importer' => ContactImporter::class,
        'total_rows' => 4,
        'processed_rows' => 0,
        'successful_rows' => 0,
    ]);

    // O mapa que a tela monta: coluna do importador => coluna do arquivo.
    $mapa = [
        'nome'           => 'nome',
        'telefone_e164'  => 'telefone',
        'email'          => 'email',
        'cidade'         => 'cidade',
        'campo_'.$contrato->id => 'Contrato',
    ];

    $linhas = array_map(
        fn ($l) => array_combine(['nome', 'telefone', 'email', 'cidade', 'Contrato'], explode(';', $l)),
        array_slice(explode("\n", $csv), 1),
    );

    $importador = new ContactImporter(
        import: $import,
        columnMap: $mapa,
        options: ['duplicados' => 'ignorar', 'etiquetas' => [$tag->id]],
    );

    $rejeitadas = [];

    foreach ($linhas as $linha) {
        try {
            $importador($linha);
        } catch (RowImportFailedException $e) {
            $rejeitadas[] = $e->getMessage();
        }
    }

    // Duas entram: Maria e Joao. Duas saem: sem telefone, e o repetido da Maria.
    expect(Contact::count())->toBe(2)
        ->and($rejeitadas)->toHaveCount(2);

    expect(implode(' | ', $rejeitadas))
        ->toContain('Telefone inválido')
        ->toContain('Já existe contato');

    $maria = Contact::where('telefone_e164', '+5584996143373')->first();

    expect($maria->nome)->toBe('Maria Souza')
        ->and($maria->email)->toBe('maria@exemplo.com')
        ->and($maria->cidade)->toBe('Natal')
        // o campo personalizado veio pela coluna "Contrato"
        ->and(ContactFieldValue::where('contact_id', $maria->id)->value('valor'))->toBe('778899')
        // e a etiqueta do lote, com a origem certa
        ->and($maria->tags->first()->nome)->toBe('Lote agosto')
        ->and($maria->tags->first()->pivot->origem)->toBe('importacao');

    // Joao entrou mesmo com e-mail e contrato em branco: coluna vazia nao impede.
    $joao = Contact::where('nome', 'João Lima')->first();

    expect($joao)->not->toBeNull()
        ->and($joao->email)->toBeNull()
        ->and($joao->cidade)->toBe('Parnamirim');
});

it('os apelidos de cabecalho cobrem o que a planilha do cliente costuma trazer', function () {
    // Sem isto, todo mundo mapeia coluna a mao — e o cabecalho de planilha de
    // cliente nunca se chama "telefone_e164".
    $telefone = collect(ContactImporter::getColumns())
        ->first(fn ($c) => $c->getName() === 'telefone_e164');

    expect($telefone->getGuesses())
        ->toContain('telefone')
        ->toContain('celular')
        ->toContain('whatsapp');
});
