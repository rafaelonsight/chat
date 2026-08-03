<?php

use App\Models\{BusinessHour, Channel, Contact, Conversation, Message, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function contaAuto(string $slug, bool $ativa = true): array
{
    $t = Tenant::create([
        'nome' => strtoupper($slug), 'slug' => $slug,
        'fuso_horario' => 'America/Sao_Paulo',
        'resposta_automatica_ativa' => $ativa,
        'resposta_automatica_texto' => 'Ola {{nome}}, estamos fora do horario. Voltamos {{proxima_abertura}}.',
    ]);
    TenantContext::set($t->id);

    $u = User::create(['tenant_id' => $t->id, 'name' => 'At', 'email' => "a@{$slug}.test", 'password' => 'segredo123']);
    $c = Channel::create(['nome' => 'C']);
    $c->refresh();
    $c->update(['status' => 'open']);

    foreach (range(0, 6) as $dia) {
        BusinessHour::create([
            'dia_semana' => $dia,
            'ativo'      => $dia !== 0,
            'intervalos' => [['inicio' => '08:30', 'fim' => '18:00']],
        ]);
    }

    return [$t, $u, $c];
}

function chegaMensagem(Channel $c, string $texto, string $id, string $jid = '5584996143373@s.whatsapp.net', array $extra = []): void
{
    test()->postJson("/webhooks/evolution/{$c->id}/{$c->webhook_secret}", [
        'event' => 'messages.upsert',
        'data'  => array_merge([
            'key'      => ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id],
            'pushName' => 'Joao',
            'message'  => ['conversation' => $texto],
        ], $extra),
    ])->assertOk();
}

beforeEach(function () {
    // id distinto por envio: messages tem unico em (channel_id, external_id), e
    // um fake devolvendo sempre o mesmo id violava a constraint no segundo envio
    // — no WhatsApp real cada mensagem tem id proprio.
    Http::fake([
        '*/message/sendText/*'    => fn () => Http::response(['key' => ['id' => 'AUTO-'.uniqid()]], 201),
        '*/group/findGroupInfos*' => Http::response(['subject' => 'Bairro Centro'], 200),
        '*'                       => Http::response([], 200),
    ]);
});

afterEach(fn () => TenantContext::forget());

it('responde fora do horario, com nome e proxima abertura', function () {
    [, , $c] = contaAuto('ra1');
    Carbon::setTestNow(Carbon::parse('2026-08-05 23:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'meu net caiu', 'M1');

    $auto = Message::where('automatica', true)->first();
    expect($auto)->not->toBeNull()
        ->and($auto->direcao)->toBe('out')
        ->and($auto->corpo)->toContain('Joao')
        ->and($auto->corpo)->toContain('08:30');

    Carbon::setTestNow();
});

// Sem isto a conversa sai de Novos e ninguem ve o cliente de manha.
it('a resposta automatica NAO tira a conversa de Novos', function () {
    [, , $c] = contaAuto('ra2');
    Carbon::setTestNow(Carbon::parse('2026-08-05 23:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'oi', 'M1');

    $cv = Conversation::first();
    expect($cv->status)->toBe(Conversation::NOVA)
        ->and($cv->atendente_id)->toBeNull();

    Carbon::setTestNow();
});

it('nao responde dentro do horario', function () {
    [, , $c] = contaAuto('ra3');
    Carbon::setTestNow(Carbon::parse('2026-08-05 10:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'oi', 'M1');

    expect(Message::where('automatica', true)->count())->toBe(0);

    Carbon::setTestNow();
});

// Cinco mensagens as 22h nao podem virar cinco respostas iguais.
it('responde uma vez so, mesmo com varias mensagens', function () {
    [, , $c] = contaAuto('ra4');
    Carbon::setTestNow(Carbon::parse('2026-08-05 23:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'oi', 'M1');
    chegaMensagem($c, 'alguem ai', 'M2');
    chegaMensagem($c, 'por favor', 'M3');

    expect(Message::where('automatica', true)->count())->toBe(1);

    Carbon::setTestNow();
});

it('rearma depois que um humano responde', function () {
    [, $u, $c] = contaAuto('ra5');
    Carbon::setTestNow(Carbon::parse('2026-08-05 23:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'oi', 'M1');
    expect(Message::where('automatica', true)->count())->toBe(1);

    // atendente responde no dia seguinte
    Carbon::setTestNow(Carbon::parse('2026-08-06 09:00', 'America/Sao_Paulo'));
    $cv = Conversation::first();
    $this->actingAs($u);
    Message::create([
        'conversation_id' => $cv->id, 'channel_id' => $c->id,
        'direcao' => 'out', 'tipo' => 'text', 'corpo' => 'bom dia', 'status' => Message::STATUS_SENT,
    ]);

    // cliente volta a noite
    Carbon::setTestNow(Carbon::parse('2026-08-06 23:00', 'America/Sao_Paulo'));
    chegaMensagem($c, 'de novo', 'M2');

    expect(Message::where('automatica', true)->count())->toBe(2);

    Carbon::setTestNow();
});

// Grupo de bairro com 40 mensagens a noite seria 40 respostas na frente de todos.
it('NUNCA responde em grupo', function () {
    [, , $c] = contaAuto('ra6');
    Carbon::setTestNow(Carbon::parse('2026-08-05 23:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'acabou a luz aqui', 'G1', '120363011111111111@g.us', [
        'key' => ['remoteJid' => '120363011111111111@g.us', 'fromMe' => false, 'id' => 'G1', 'participant' => '5584911111111@s.whatsapp.net'],
    ]);

    expect(Contact::first()->eGrupo())->toBeTrue()
        ->and(Message::where('automatica', true)->count())->toBe(0);

    Carbon::setTestNow();
});

it('nao responde quando desligado', function () {
    [, , $c] = contaAuto('ra7', ativa: false);
    Carbon::setTestNow(Carbon::parse('2026-08-05 23:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'oi', 'M1');

    expect(Message::where('automatica', true)->count())->toBe(0);

    Carbon::setTestNow();
});

it('mensagem automatica nao aparece como do atendente no relatorio', function () {
    [, , $c] = contaAuto('ra8');
    Carbon::setTestNow(Carbon::parse('2026-08-05 23:00', 'America/Sao_Paulo'));

    chegaMensagem($c, 'oi', 'M1');

    $auto = Message::where('automatica', true)->first();
    expect($auto->automatica)->toBeTrue();

    Carbon::setTestNow();
});
