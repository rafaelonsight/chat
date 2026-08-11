<?php

use App\Events\RecadoDireto;
use App\Livewire\ChatInterno;
use App\Models\{Channel, DirectMessage, Team, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
 * O CHAT DA EQUIPE — a bolha no canto do painel.
 *
 * RECADO PARA QUEM ESTA OFFLINE TAMBEM, e isso e o centro: pedido explicito do Rafael. A
 * presenca so pinta a bolinha; a mensagem vive no banco e espera. Travar quem esta fora
 * transformaria uma caixa de recados numa sala de espera.
 *
 * E ELE NAO SEGUE O ACESSO POR CANAL E TIME, de proposito. Aquela regra diz o que a pessoa pode
 * ver do CLIENTE; aqui nao ha cliente nenhum. Um atendente restrito a um canal continua
 * precisando falar com o chefe — e se ele nao puder, alguem vai usar o WhatsApp pessoal, que e
 * exatamente o que este recurso existe para evitar.
 */

function doisColegas(string $slug): array
{
    $t = Tenant::create(['nome' => strtoupper($slug), 'slug' => $slug]);
    TenantContext::set($t->id);

    $ana = User::create([
        'tenant_id' => $t->id, 'name' => 'Ana Souza', 'email' => "ana@{$slug}.test",
        'password' => 'segredo123',
    ]);

    $bruno = User::create([
        'tenant_id' => $t->id, 'name' => 'Bruno Dias', 'email' => "bruno@{$slug}.test",
        'password' => 'segredo123',
    ]);

    return [$t, $ana, $bruno];
}

afterEach(fn () => TenantContext::forget());

it('manda recado para quem esta offline, e ele fica esperando', function () {
    [$t, $ana, $bruno] = doisColegas('ci1');

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->call('abrir', $bruno->id)
        ->set('texto', 'quando puder, me liga')
        ->call('enviar')
        ->assertSet('texto', '');

    $recado = DirectMessage::first();

    expect($recado->de_user_id)->toBe($ana->id)
        ->and($recado->para_user_id)->toBe($bruno->id)
        ->and($recado->corpo)->toBe('quando puder, me liga')
        ->and($recado->lida_em)->toBeNull();
});

it('o recado avisa o destinatario, e so ele', function () {
    // Canal do proprio: recado direto trafegando em canal coletivo e recado que qualquer um
    // com o console aberto le.
    Event::fake([RecadoDireto::class]);

    [$t, $ana, $bruno] = doisColegas('ci2');

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->call('abrir', $bruno->id)
        ->set('texto', 'oi')
        ->call('enviar');

    Event::assertDispatched(RecadoDireto::class, function (RecadoDireto $e) use ($bruno) {
        $canais = collect($e->broadcastOn())->map(fn ($c) => $c->name)->all();

        return $canais === ['private-recados.'.$bruno->id];
    });
});

it('a conversa mostra os dois lados', function () {
    // O erro facil aqui e filtrar so "de mim para ele": da um monologo com cara de conversa, e
    // ninguem percebe ate alguem responder.
    [$t, $ana, $bruno] = doisColegas('ci3');

    DirectMessage::create(['tenant_id' => $t->id, 'de_user_id' => $ana->id, 'para_user_id' => $bruno->id, 'corpo' => 'oi']);
    DirectMessage::create(['tenant_id' => $t->id, 'de_user_id' => $bruno->id, 'para_user_id' => $ana->id, 'corpo' => 'fala']);

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->call('abrir', $bruno->id)
        ->assertViewHas('conversa', fn ($c) => $c->pluck('corpo')->all() === ['oi', 'fala']);
});

it('abrir a conversa marca como lido o que era para mim', function () {
    [$t, $ana, $bruno] = doisColegas('ci4');

    DirectMessage::create(['tenant_id' => $t->id, 'de_user_id' => $bruno->id, 'para_user_id' => $ana->id, 'corpo' => 'urgente']);
    $meu = DirectMessage::create(['tenant_id' => $t->id, 'de_user_id' => $ana->id, 'para_user_id' => $bruno->id, 'corpo' => 'ja vi']);

    Livewire::actingAs($ana)->test(ChatInterno::class)->call('abrir', $bruno->id);

    expect(DirectMessage::where('para_user_id', $ana->id)->whereNull('lida_em')->count())->toBe(0)
        // E o que eu mandei continua nao lido: quem le e o outro.
        ->and($meu->fresh()->lida_em)->toBeNull();
});

it('o contador soma so o que ainda nao li', function () {
    [$t, $ana, $bruno] = doisColegas('ci5');

    DirectMessage::create(['tenant_id' => $t->id, 'de_user_id' => $bruno->id, 'para_user_id' => $ana->id, 'corpo' => 'um']);
    DirectMessage::create(['tenant_id' => $t->id, 'de_user_id' => $bruno->id, 'para_user_id' => $ana->id, 'corpo' => 'dois']);
    DirectMessage::create(['tenant_id' => $t->id, 'de_user_id' => $ana->id, 'para_user_id' => $bruno->id, 'corpo' => 'tres']);

    Livewire::actingAs($ana)->test(ChatInterno::class)->assertViewHas('total', 2);
});

it('nao manda recado para gente de outra conta', function () {
    /*
     * O id vem da tela, e id que vem da tela e um palpite ate ser conferido. Sem esta checagem,
     * trocar um numero no navegador entregaria recado a uma pessoa de outra empresa.
     */
    [$t, $ana, $bruno] = doisColegas('ci6');

    $outra = Tenant::create(['nome' => 'Outra', 'slug' => 'ci6-outra']);
    $estranho = User::create([
        'tenant_id' => $outra->id, 'name' => 'Estranho', 'email' => 'x@ci6.test',
        'password' => 'segredo123',
    ]);

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->set('comQuem', $estranho->id)
        ->set('texto', 'ola')
        ->call('enviar');

    expect(DirectMessage::withoutGlobalScope('tenant')->count())->toBe(0);
});

it('nao manda recado para si mesmo', function () {
    [$t, $ana, $bruno] = doisColegas('ci7');

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->set('comQuem', $ana->id)
        ->set('texto', 'lembrete')
        ->call('enviar');

    expect(DirectMessage::count())->toBe(0);
});

it('a lista traz todo mundo da conta, menos eu', function () {
    [$t, $ana, $bruno] = doisColegas('ci8');

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->assertViewHas('pessoas', fn ($p) => $p->pluck('id')->all() === [$bruno->id]);
});

it('o filtro por equipe recorta a lista', function () {
    [$t, $ana, $bruno] = doisColegas('ci9');

    $carla = User::create([
        'tenant_id' => $t->id, 'name' => 'Carla Lima', 'email' => 'carla@ci9.test',
        'password' => 'segredo123',
    ]);

    $suporte = Team::create(['nome' => 'Suporte']);
    $bruno->teams()->attach($suporte->id);

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->set('equipe', $suporte->id)
        ->assertViewHas('pessoas', fn ($p) => $p->pluck('id')->all() === [$bruno->id])
        ->set('equipe', null)
        ->assertViewHas('pessoas', fn ($p) => $p->pluck('id')->sort()->values()->all()
            === collect([$bruno->id, $carla->id])->sort()->values()->all());
});

it('quem esta restrito a um canal continua falando com todo mundo', function () {
    /*
     * DECISAO EXPLICITA, e nao esquecimento. O acesso por canal e time protege o que a pessoa ve
     * do CLIENTE. Se ele valesse aqui, o atendente restrito a um canal ficaria sem poder falar
     * com metade da equipe — e ia usar o WhatsApp pessoal, que e o que este recurso existe para
     * evitar. Se um dia isto virar restricao, que seja por decisao, e este teste vai quebrar
     * para obrigar a decisao a ser tomada.
     */
    [$t, $ana, $bruno] = doisColegas('ci10');

    $canal = Channel::create(['nome' => 'Vendas']);
    $outro = Channel::create(['nome' => 'Suporte']);

    $ana->canais()->attach($canal->refresh()->id);
    $bruno->canais()->attach($outro->refresh()->id);

    Livewire::actingAs($ana)
        ->test(ChatInterno::class)
        ->assertViewHas('pessoas', fn ($p) => $p->pluck('id')->all() === [$bruno->id])
        ->call('abrir', $bruno->id)
        ->set('texto', 'preciso de uma ajuda')
        ->call('enviar');

    expect(DirectMessage::count())->toBe(1);
});
