<?php

use App\Models\{Channel, Chatbot, ChatbotAction, Contact, Conversation, Tag, Tenant, User};
use App\Services\{ChatbotFluxo, Etiquetador};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake(['*' => fn () => Http::response(['key' => ['id' => 'A-'.uniqid()]])]);

    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();

    $this->cliente = Tag::create(['nome' => 'Cliente', 'cor' => 'verde']);
    $this->inadimplente = Tag::create(['nome' => 'Inadimplente', 'cor' => 'vermelho']);

    $this->contato = Contact::create([
        'nome' => 'Rafael', 'telefone_e164' => '+5511999998888',
        'jid' => '5511999998888@s.whatsapp.net',
    ]);
});

afterEach(fn () => TenantContext::forget());

it('nao aceita duas etiquetas com o mesmo nome, mesmo trocando maiuscula', function () {
    // O indice e sobre lower(nome): "cliente" e "Cliente" sao a mesma etiqueta, e
    // duas iguais na lista de escolha e confusao pura.
    expect(fn () => Tag::create(['nome' => 'CLIENTE', 'cor' => 'azul']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('registra quem aplicou e por qual caminho', function () {
    app(Etiquetador::class)->aplicar($this->contato, [$this->cliente->id], Etiquetador::MANUAL, $this->usuario->id);

    $pivo = $this->contato->tags()->first()->pivot;

    expect($pivo->origem)->toBe('manual')
        ->and($pivo->aplicado_por)->toBe($this->usuario->id);
});

it('a primeira aplicacao e a que conta: nao reescreve a origem', function () {
    $e = app(Etiquetador::class);

    $e->aplicar($this->contato, [$this->cliente->id], Etiquetador::MANUAL, $this->usuario->id);
    $e->aplicar($this->contato, [$this->cliente->id], Etiquetador::CHATBOT);

    // Se reescrevesse, o rastro de quem colocou primeiro seria perdido — e essa e a
    // primeira pergunta quando uma etiqueta aparece errada.
    expect($this->contato->tags()->first()->pivot->origem)->toBe('manual')
        ->and($this->contato->tags()->count())->toBe(1);
});

it('o banco recusa origem inventada', function () {
    expect(fn () => $this->contato->tags()->attach($this->cliente->id, ['origem' => 'sei_la']))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('sincronizar deixa exatamente as etiquetas escolhidas', function () {
    $e = app(Etiquetador::class);
    $e->aplicar($this->contato, [$this->cliente->id, $this->inadimplente->id]);

    $e->sincronizar($this->contato, [$this->inadimplente->id]);

    expect($this->contato->tags()->pluck('nome')->all())->toBe(['Inadimplente']);
});

it('o chatbot aplica e remove etiqueta pelo mesmo caminho, com origem chatbot', function () {
    $fluxo = app(ChatbotFluxo::class);

    $bot = Chatbot::create([
        'nome' => 'Fluxo', 'ativo' => true, 'status' => Chatbot::PUBLICADO,
        'mensagem_nao_entendi' => 'x', 'mensagem_boas_vindas' => 'y',
    ]);

    // ja tem uma etiqueta que o fluxo vai remover
    app(Etiquetador::class)->aplicar($this->contato, [$this->inadimplente->id], Etiquetador::MANUAL);

    $inicio = $fluxo->garantirInicio($bot);
    $bloco = $fluxo->criarPasso($bot, 300, 100, 'Marca');
    $fluxo->adicionarAcao($bloco, ChatbotAction::ETIQUETA, [
        'adicionar' => [$this->cliente->id],
        'remover'   => [$this->inadimplente->id],
    ]);
    $fluxo->adicionarAcao($bloco, ChatbotAction::MENSAGEM, ['texto' => 'Pronto!']);
    $fluxo->ligar($inicio, $bloco);

    $conversa = Conversation::abertaOuNova($this->channel->id, $this->contato->id);

    $this->postJson("/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}", [
        'event' => 'messages.upsert',
        'data'  => [
            'key' => ['remoteJid' => $this->contato->jid, 'fromMe' => false, 'id' => 'ET1'],
            'pushName' => 'Rafael', 'message' => ['conversation' => 'oi'],
            'messageTimestamp' => now()->timestamp,
        ],
    ])->assertOk();

    $nomes = $this->contato->fresh()->tags()->pluck('nome')->all();

    expect($nomes)->toBe(['Cliente']);
    expect($this->contato->fresh()->tags()->first()->pivot->origem)->toBe('chatbot');

    // Etiquetar e efeito colateral: o fluxo NAO para nela.
    expect(\App\Models\Message::where('conversation_id', $conversa->id)
        ->where('automatica', true)->pluck('corpo')->all())->toBe(['Pronto!']);
});

it('a paleta tem 24 cores e nenhuma repete pilula ou ponto', function () {
    $chaves = array_keys(Tag::PALETA);

    expect($chaves)->toHaveCount(24);

    $pilulas = array_map(fn ($c) => Tag::pilula($c), $chaves);
    $pontos = array_map(fn ($c) => Tag::ponto($c), $chaves);

    // Duas cores com o mesmo visual seriam duas opcoes que o usuario nao
    // consegue distinguir na grade — pior que ter menos cores.
    expect(array_unique($pilulas))->toHaveCount(24)
        ->and(array_unique($pontos))->toHaveCount(24);
});

it('toda cor da paleta tem classe e ponto proprios', function () {
    foreach (array_keys(Tag::PALETA) as $cor) {
        $t = new Tag(['nome' => 'x', 'cor' => $cor]);

        expect($t->classes())->toBeString()->not->toBeEmpty();
        expect($t->pontinho())->toBeString()->not->toBeEmpty();

        if ($cor !== 'cinza') {
            expect($t->pontinho())->not->toBe('bg-gray-400', "a cor {$cor} caiu no cinza");
        }
    }
});

it('as 12 cores antigas continuam na paleta', function () {
    // Etiqueta ja salva com uma dessas nao pode virar cinza porque a paleta cresceu.
    $antigas = ['cinza', 'vermelho', 'laranja', 'ambar', 'verde', 'esmeralda',
        'ciano', 'azul', 'indigo', 'violeta', 'rosa', 'marrom'];

    foreach ($antigas as $cor) {
        expect(Tag::PALETA)->toHaveKey($cor);

        // Cinza e o proprio default: so as outras onze provam que nao caiu nele.
        if ($cor !== 'cinza') {
            expect(Tag::ponto($cor))->not->toBe('bg-gray-400', "{$cor} perdeu a cor");
        }
    }
});

it('cor desconhecida cai no cinza em vez de quebrar a tela', function () {
    $t = new Tag(['nome' => 'x', 'cor' => 'arco-iris']);

    expect($t->pontinho())->toBe('bg-gray-400')
        ->and($t->corLabel())->toBe('arco-iris');
});

/** Admin logado num tenant proprio, com o painel resolvido para teste Livewire. */
function adminEtiqueta(string $slug): User
{
    $t = Tenant::create(['nome' => 'T', 'slug' => $slug]);
    TenantContext::set($t->id);

    $u = User::create([
        'tenant_id' => $t->id, 'name' => 'U', 'email' => "{$slug}@t.test",
        'password' => 'segredo123', 'admin' => true,
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');

    return $u;
}

it('criar etiqueta abre modal em vez de trocar de pagina', function () {
    // E a ausencia da rota que faz o CreateAction virar modal: se alguem
    // registrar a pagina de volta, o modal desaparece sem aviso.
    expect(fn () => \App\Filament\Resources\Tags\TagResource::getUrl('create'))
        ->toThrow(Exception::class);

    expect(array_keys(\App\Filament\Resources\Tags\TagResource::getPages()))
        ->toBe(['index', 'edit']);
});

it('o seletor mostra as cores, nao o nome delas num select', function () {
    $u = adminEtiqueta('tcor');
    $tag = Tag::create(['nome' => 'Teste Cor', 'cor' => 'azul']);

    // O modal do Filament so monta no navegador, entao o HTML do seletor e
    // conferido na pagina de edicao — mesmo TagForm, mesma blade.
    $html = $this->actingAs($u)
        ->get("/admin/tags/{$tag->id}/edit")
        ->assertSuccessful()
        ->getContent();

    // Uma bolinha por cor da paleta, com o rotulo no title para quem passa o mouse.
    foreach (Tag::PALETA as $chave => $dados) {
        expect($html)->toContain(Tag::ponto($chave));
        expect($html)->toContain('title="'.$dados['label'].'"');
    }

    expect(substr_count($html, 'role="radio"'))->toBe(24);

    // A previa le o nome do campo irmao, cujo caminho e derivado de 'data.cor'.
    // Se o Filament trocar esse prefixo, a previa para de atualizar e isso so
    // apareceria no navegador — aqui quebra o teste.
    $plano = html_entity_decode($html, ENT_QUOTES);

    preg_match_all('/entangle\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $plano, $m);

    expect($m[1])->toContain('data.cor')
        ->and($m[1])->toContain('data.nome');
});

it('o modal salva a etiqueta com a cor escolhida', function () {
    $u = adminEtiqueta('tsalva');

    \Livewire\Livewire::actingAs($u)
        ->test(\App\Filament\Resources\Tags\Pages\ListTags::class)
        ->mountAction('create')
        ->setActionData(['nome' => 'VIP', 'cor' => 'turquesa'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Tag::where('nome', 'VIP')->first()?->cor)->toBe('turquesa');
});

it('o modal recusa cor que nao esta na paleta', function () {
    $u = adminEtiqueta('tinvalida');

    // A grade so oferece as 24, mas o valor chega do navegador: sem a regra no
    // servidor uma cor inventada entraria no banco e sairia cinza na tela.
    \Livewire\Livewire::actingAs($u)
        ->test(\App\Filament\Resources\Tags\Pages\ListTags::class)
        ->mountAction('create')
        ->setActionData(['nome' => 'Arco', 'cor' => 'arco-iris'])
        ->callMountedAction()
        ->assertHasActionErrors(['cor']);

    expect(Tag::where('nome', 'Arco')->exists())->toBeFalse();
});
