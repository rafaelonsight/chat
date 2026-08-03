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

it('toda cor da paleta tem classe e ponto proprios', function () {
    foreach (array_keys(Tag::CORES) as $cor) {
        $t = new Tag(['nome' => 'x', 'cor' => $cor]);

        expect($t->classes())->toBeString()->not->toBeEmpty();
        expect($t->pontinho())->toBeString()->not->toBeEmpty();

        if ($cor !== 'cinza') {
            expect($t->pontinho())->not->toBe('bg-gray-400', "a cor {$cor} caiu no cinza");
        }
    }
});
