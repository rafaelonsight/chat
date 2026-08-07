<?php

use App\Livewire\Inbox\ConversationWindow;
use App\Models\{Channel, Contact, Conversation, Message, Tenant, User};
use App\Services\PesquisaDeSatisfacao;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Pesquisa de satisfacao.
 *
 * O PROBLEMA QUE DOMINA ESTE ARQUIVO: a nota chega DEPOIS do encerramento, e no WhatsApp isso
 * quer dizer que ela chega numa CONVERSA NOVA. O cliente responde "5", o sistema abre um
 * atendimento novo, e o atendente ve surgir em Novos uma conversa cujo unico conteudo e o
 * numero 5. Sem tratar isso, a pesquisa cria mais trabalho do que informacao — e a primeira
 * coisa que o dono faz e desligar.
 *
 * Entao a nota e gravada na conversa QUE FOI ENCERRADA, e a conversa recem-aberta se fecha na
 * hora, sem contar como nao lida.
 */

beforeEach(function () {
    $this->conta = Tenant::create([
        'nome' => 'Conta', 'slug' => 'pesquisa', 'pesquisa_ativa' => true,
    ]);
    TenantContext::set($this->conta->id);

    $this->pessoa = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Atendente',
        'email' => 'atendente@pesquisa.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'pes',
    ]);

    $this->contato = Contact::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Cliente',
        'telefone_e164' => '+5541999990000', 'jid' => '5541999990000@s.whatsapp.net',
    ]);

    $this->conversa = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'atendente_id' => $this->pessoa->id, 'ultima_entrada_em' => now(),
    ]);

    $this->actingAs($this->pessoa);
    Queue::fake();
    Http::fake(['*' => Http::response(['key' => ['id' => 'X']], 200)]);
});

/**
 * A resposta do cliente, que abre uma conversa nova porque a anterior foi encerrada.
 *
 * Arquiva o que estiver aberto antes de criar: o banco tem indice unico de UMA conversa aberta
 * por contato e canal, e ele esta certo — duas abertas para a mesma pessoa no mesmo numero
 * seria a mesma conversa em dois lugares. Meu ajudante violava isso, e o teste so passou
 * quando ele passou a imitar o que acontece de verdade.
 */
function respostaDoCliente(string $texto, $ctx): Message
{
    Conversation::withoutGlobalScope('tenant')
        ->where('contact_id', $ctx->contato->id)
        ->where('channel_id', $ctx->canal->id)
        ->where('status', '!=', Conversation::ARQUIVADA)
        ->update(['status' => Conversation::ARQUIVADA]);

    $nova = Conversation::create([
        'tenant_id' => $ctx->conta->id, 'channel_id' => $ctx->canal->id,
        'contact_id' => $ctx->contato->id, 'status' => Conversation::NOVA,
        'nao_lidas' => 1, 'ultima_entrada_em' => now(),
    ]);

    return Message::create([
        'tenant_id' => $ctx->conta->id, 'conversation_id' => $nova->id,
        'channel_id' => $ctx->canal->id, 'direcao' => 'in', 'tipo' => 'text',
        'corpo' => $texto, 'external_id' => 'W-'.uniqid(), 'status' => Message::STATUS_DELIVERED,
    ]);
}

// --------------------------------------------------------------- a pergunta

it('pergunta ao encerrar, quando a conta pediu', function () {
    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('finalizar');

    $pergunta = Message::where('direcao', 'out')->latest('id')->first();

    expect($pergunta)->not->toBeNull()
        ->and($pergunta->corpo)->toContain('1 a 5')
        // automatica: nao e o atendente falando, e nao pode contar como resposta dele no
        // relatorio de tempo.
        ->and($pergunta->automatica)->toBeTrue()
        ->and($this->conversa->fresh()->pesquisa_enviada_em)->not->toBeNull();
});

it('nao pergunta quando a conta nao pediu', function () {
    $this->conta->update(['pesquisa_ativa' => false]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $this->conversa->id])
        ->call('finalizar');

    expect(Message::where('direcao', 'out')->count())->toBe(0)
        ->and($this->conversa->fresh()->pesquisa_enviada_em)->toBeNull();
});

it('nao pergunta com a janela de 24h fechada no canal oficial', function () {
    // Texto livre e recusado pela Meta fora da janela. Perguntar assim mesmo encheria a
    // conversa de bolha vermelha por causa de uma pesquisa que ninguem pediu.
    $oficial = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Oficial', 'tipo' => 'meta_cloud',
        'status' => 'open', 'meta_phone_number_id' => '1', 'meta_waba_id' => '2', 'meta_token' => 't',
    ]);

    $fechada = Conversation::create([
        'tenant_id' => $this->conta->id, 'channel_id' => $oficial->id,
        'contact_id' => $this->contato->id, 'status' => Conversation::EM_ATENDIMENTO,
        'ultima_entrada_em' => now()->subHours(30),
    ]);

    Livewire::actingAs($this->pessoa)
        ->test(ConversationWindow::class, ['conversationId' => $fechada->id])
        ->call('finalizar');

    expect($fechada->fresh()->pesquisa_enviada_em)->toBeNull();
});

// ----------------------------------------------------------------- a resposta

it('a nota vai para a conversa ENCERRADA, e nao para a que a resposta abriu', function () {
    $this->conversa->forceFill(['pesquisa_enviada_em' => now(), 'status' => Conversation::ARQUIVADA])->save();

    $resposta = respostaDoCliente('5', $this);

    expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($resposta))->toBeTrue()
        ->and($this->conversa->fresh()->satisfacao)->toBe(5)
        ->and($this->conversa->fresh()->satisfacao_em)->not->toBeNull()
        // e a conversa nova nao ficou com a nota
        ->and($resposta->conversation->fresh()->satisfacao)->toBeNull();
});

it('a conversa que a resposta abriu se fecha, sem virar fila', function () {
    // Sem isto a pesquisa geraria, em Novos, uma conversa por cliente cujo unico conteudo e
    // o numero 5 — mais trabalho do que informacao.
    $this->conversa->forceFill(['pesquisa_enviada_em' => now(), 'status' => Conversation::ARQUIVADA])->save();

    $resposta = respostaDoCliente('4', $this);
    app(PesquisaDeSatisfacao::class)->talvezRegistrar($resposta);

    $nova = $resposta->conversation->fresh();

    expect($nova->status)->toBe(Conversation::ARQUIVADA)
        ->and($nova->nao_lidas)->toBe(0);
});

it('aceita o jeito que gente escreve', function () {
    foreach (['5', ' 5 ', '5!', 'nota 5', '5 :)'] as $texto) {
        $conversa = Conversation::create([
            'tenant_id' => $this->conta->id, 'channel_id' => $this->canal->id,
            'contact_id' => $this->contato->id, 'status' => Conversation::ARQUIVADA,
            'pesquisa_enviada_em' => now(),
        ]);

        $resposta = respostaDoCliente($texto, $this);

        expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($resposta))
            ->toBeTrue("deveria aceitar: {$texto}");

        expect($conversa->fresh()->satisfacao)->toBe(5, "nota errada para: {$texto}");
    }
});

it('NAO transforma qualquer numero em nota', function () {
    // "vou levar 5 caixas" nao e avaliacao. Virar nota poluiria o unico numero que o dono
    // vai olhar — e um numero de satisfacao em que ninguem confia nao serve para nada.
    $this->conversa->forceFill(['pesquisa_enviada_em' => now(), 'status' => Conversation::ARQUIVADA])->save();

    foreach (['vou levar 5 caixas', 'meu contrato é 12345', 'bom dia', '7', '0'] as $texto) {
        $resposta = respostaDoCliente($texto, $this);

        expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($resposta))
            ->toBeFalse("nao deveria aceitar: {$texto}");
    }

    expect($this->conversa->fresh()->satisfacao)->toBeNull();
});

it('numero solto sem pesquisa aberta continua sendo so um numero', function () {
    // Nenhuma pesquisa foi enviada: "3" e a quantidade que o cliente quer comprar.
    $resposta = respostaDoCliente('3', $this);

    expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($resposta))->toBeFalse()
        ->and($resposta->conversation->fresh()->status)->toBe(Conversation::NOVA);
});

it('nota que chega tarde demais nao conta', function () {
    $this->conversa->forceFill([
        'pesquisa_enviada_em' => now()->subHours(PesquisaDeSatisfacao::HORAS + 1),
        'status' => Conversation::ARQUIVADA,
    ])->save();

    $resposta = respostaDoCliente('5', $this);

    expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($resposta))->toBeFalse()
        ->and($this->conversa->fresh()->satisfacao)->toBeNull();
});

it('nao sobrescreve nota ja dada', function () {
    $this->conversa->forceFill([
        'pesquisa_enviada_em' => now(), 'satisfacao' => 5, 'satisfacao_em' => now(),
        'status' => Conversation::ARQUIVADA,
    ])->save();

    $resposta = respostaDoCliente('1', $this);

    expect(app(PesquisaDeSatisfacao::class)->talvezRegistrar($resposta))->toBeFalse()
        ->and($this->conversa->fresh()->satisfacao)->toBe(5);
});

it('o banco recusa nota fora de 1 a 5', function () {
    // CHECK no banco: nota fora da faixa nao e nota errada, e sinal de que alguem escreveu no
    // lugar errado. Melhor o banco recusar do que o relatorio tirar media de um 47.
    expect(fn () => $this->conversa->forceFill(['satisfacao' => 47])->save())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('a nota fica na CONVERSA e nao no contato', function () {
    // Um cliente pode ser bem atendido hoje e mal atendido no mes que vem. Uma nota por
    // pessoa apagaria a diferenca, que e justamente o que o dono precisa enxergar.
    expect(Illuminate\Support\Facades\Schema::hasColumn('conversations', 'satisfacao'))->toBeTrue()
        ->and(Illuminate\Support\Facades\Schema::hasColumn('contacts', 'satisfacao'))->toBeFalse();
});
