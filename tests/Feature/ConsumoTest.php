<?php

use App\Jobs\FecharConsumoDoMes;
use App\Models\{Channel, ConsumoMensal, Contact, Conversation, Message, Tenant, User};
use App\Services\Medidor;
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * Consumo de conversas: o numero que vira fatura.
 *
 * DUAS DECISOES MANDAM AQUI.
 *
 * 1. A UNIDADE E A CONVERSA INICIADA. Nao mensagem — duas empresas com o mesmo numero de
 *    clientes pagariam valores muito diferentes so porque uma escreve mais. Nao contato — o
 *    cliente que volta todo mes daria receita uma vez so.
 *
 * 2. MES FECHADO E FOTO, NAO CONSULTA. Se fosse recalculado, apagar um canal levaria as
 *    conversas por cascata e o julho ja faturado encolheria em setembro. Cobranca que muda
 *    depois de emitida e discussao com o cliente — que ele ganha.
 */

beforeEach(function () {
    $this->conta = Tenant::create(['nome' => 'Conta', 'slug' => 'consumo']);
    TenantContext::set($this->conta->id);

    $this->admin = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'Admin',
        'email' => 'admin@consumo.test', 'password' => 'segredo123', 'admin' => true,
    ]);

    $this->canal = Channel::create([
        'tenant_id' => $this->conta->id, 'nome' => 'Canal',
        'tipo' => 'evolution', 'status' => 'open', 'instance_name' => 'con',
    ]);

    $this->medidor = app(Medidor::class);
    $this->actingAs($this->admin);
});

function conversaEm($ctx, $quando, int $entradas = 1, int $saidas = 1): Conversation
{
    static $n = 0;
    $n++;

    $contato = Contact::create([
        'tenant_id' => $ctx->conta->id, 'nome' => 'C'.$n,
        'telefone_e164' => '+55419700000'.$n, 'jid' => '55419700000'.$n.'@s.whatsapp.net',
    ]);

    $c = Conversation::create([
        'tenant_id' => $ctx->conta->id, 'channel_id' => $ctx->canal->id,
        'contact_id' => $contato->id, 'ultima_msg_em' => $quando,
    ]);

    $c->forceFill(['created_at' => $quando])->save();

    foreach (range(1, max(0, $entradas)) as $i) {
        Message::create([
            'tenant_id' => $ctx->conta->id, 'conversation_id' => $c->id,
            'channel_id' => $ctx->canal->id, 'direcao' => 'in', 'tipo' => 'text',
            'corpo' => 'oi', 'status' => Message::STATUS_DELIVERED,
        ])->forceFill(['created_at' => $quando])->save();
    }

    foreach (range(1, max(0, $saidas)) as $i) {
        Message::create([
            'tenant_id' => $ctx->conta->id, 'conversation_id' => $c->id,
            'channel_id' => $ctx->canal->id, 'direcao' => 'out', 'tipo' => 'text',
            'corpo' => 'ola', 'status' => Message::STATUS_SENT,
        ])->forceFill(['created_at' => $quando])->save();
    }

    return $c;
}

// ================================================================ a medida

it('conta conversas iniciadas no mes', function () {
    conversaEm($this, now()->startOfMonth()->addDays(2));
    conversaEm($this, now()->startOfMonth()->addDays(5));
    conversaEm($this, now()->subMonthNoOverflow());

    expect($this->medidor->doMes($this->conta->id, now())['conversas'])->toBe(2);
});

it('cinco mensagens no mesmo atendimento continuam sendo UMA conversa', function () {
    // E a diferenca entre cobrar por atendimento e cobrar por quem escreve mais.
    conversaEm($this, now()->startOfMonth()->addDay(), entradas: 5, saidas: 4);

    $n = $this->medidor->doMes($this->conta->id, now());

    expect($n['conversas'])->toBe(1)
        ->and($n['recebidas'])->toBe(5)
        ->and($n['enviadas'])->toBe(4);
});

it('conta pessoas diferentes separado das conversas', function () {
    conversaEm($this, now()->startOfMonth()->addDay());
    conversaEm($this, now()->startOfMonth()->addDays(2));

    expect($this->medidor->doMes($this->conta->id, now())['contatos'])->toBe(2);
});

it('nao mistura o consumo de outra conta', function () {
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-consumo']);

    conversaEm($this, now()->startOfMonth()->addDay());

    expect($this->medidor->doMes($outra->id, now())['conversas'])->toBe(0);
});

// ============================================================ a foto do mes

it('fecha o mes e guarda os numeros', function () {
    $passado = now()->subMonthNoOverflow();
    conversaEm($this, $passado->copy()->startOfMonth()->addDays(3), entradas: 2, saidas: 3);

    $foto = $this->medidor->fechar($this->conta, $passado);

    expect($foto->conversas)->toBe(1)
        ->and($foto->mensagens_recebidas)->toBe(2)
        ->and($foto->mensagens_enviadas)->toBe(3)
        ->and($foto->fechado_em)->not->toBeNull();
});

it('o mes fechado NAO muda quando o canal e apagado depois', function () {
    // O teste que justifica a tabela existir. Apagar canal leva as conversas por cascata; sem
    // a foto, o mes faturado encolheria sozinho.
    $passado = now()->subMonthNoOverflow();
    conversaEm($this, $passado->copy()->startOfMonth()->addDays(3));

    $foto = $this->medidor->fechar($this->conta, $passado);
    expect($foto->conversas)->toBe(1);

    $this->canal->delete();

    expect(ConsumoMensal::find($foto->id)->conversas)->toBe(1)
        // e o calculo ao vivo, esse sim, ja nao acha mais nada
        ->and($this->medidor->doMes($this->conta->id, $passado)['conversas'])->toBe(0);
});

it('fechar duas vezes nao reescreve nem duplica', function () {
    $passado = now()->subMonthNoOverflow();
    conversaEm($this, $passado->copy()->startOfMonth()->addDays(3));

    $primeira = $this->medidor->fechar($this->conta, $passado);

    conversaEm($this, $passado->copy()->startOfMonth()->addDays(4));

    $segunda = $this->medidor->fechar($this->conta, $passado);

    expect($segunda->id)->toBe($primeira->id)
        // continua 1: a foto ja estava tirada, e refazer tiraria a razao de ela existir
        ->and($segunda->conversas)->toBe(1)
        ->and(ConsumoMensal::count())->toBe(1);
});

it('o job fecha o mes anterior de todas as contas', function () {
    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'outra-job']);

    (new FecharConsumoDoMes)->handle($this->medidor);

    expect(ConsumoMensal::where('tenant_id', $this->conta->id)->count())->toBe(1)
        ->and(ConsumoMensal::where('tenant_id', $outra->id)->count())->toBe(1)
        ->and(ConsumoMensal::first()->mes->format('Y-m'))
        ->toBe(now()->subMonthNoOverflow()->format('Y-m'));
});

// ================================================================== a tela

it('a tela mostra o mes corrente ao vivo', function () {
    conversaEm($this, now()->startOfMonth()->addDay());

    Livewire::actingAs($this->admin)
        ->test(App\Filament\Pages\ConsumoConversas::class)
        ->assertSee('Conversas')
        ->assertSee('é o que conta na fatura');
});

it('a tela diz o que o numero significa antes de mostra-lo', function () {
    // Base de cobranca sem definicao escrita vira discussao no primeiro boleto.
    Livewire::actingAs($this->admin)
        ->test(App\Filament\Pages\ConsumoConversas::class)
        ->assertSee('A conta é por conversa iniciada');
});

it('o cliente ve so a conta dele', function () {
    $outra = Tenant::create(['nome' => 'Empresa Alheia', 'slug' => 'alheia-consumo']);

    $dados = Livewire::actingAs($this->admin)
        ->test(App\Filament\Pages\ConsumoConversas::class)
        ->viewData('linhas');

    expect($dados)->toHaveCount(1)
        ->and($dados->first()['conta']->id)->toBe($this->conta->id);
});

it('quem revende ve todas as contas, porque e quem fatura', function () {
    Tenant::create(['nome' => 'Empresa Alheia', 'slug' => 'alheia-2']);

    $this->admin->forceFill(['operador' => true])->save();

    $dados = Livewire::actingAs($this->admin->fresh())
        ->test(App\Filament\Pages\ConsumoConversas::class)
        ->viewData('linhas');

    expect($dados->count())->toBeGreaterThan(1);
});

it('atendente comum nao entra', function () {
    $atendente = User::create([
        'tenant_id' => $this->conta->id, 'name' => 'At',
        'email' => 'at@consumo.test', 'password' => 'segredo123', 'admin' => false,
    ]);

    $this->actingAs($atendente);

    expect(App\Filament\Pages\ConsumoConversas::canAccess())->toBeFalse();
});
