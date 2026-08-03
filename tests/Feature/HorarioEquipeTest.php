<?php

use App\Models\{BusinessHour, Channel, Contact, Conversation, Message, Team, Tenant};
use App\Services\BusinessHours;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 't']);
    $this->tenant->forceFill(['fuso_horario' => 'America/Sao_Paulo'])->save();
    TenantContext::set($this->tenant->id);

    $this->channel = Channel::create(['nome' => 'Principal'])->refresh();
    $this->suporte = Team::create(['nome' => 'Suporte']);
});

afterEach(function () {
    TenantContext::forget();
    Carbon::setTestNow();
});

function grade(int $dia, string $inicio, string $fim, array $escopo = []): BusinessHour
{
    return BusinessHour::create(array_merge([
        'dia_semana' => $dia,
        'ativo'      => true,
        'intervalos' => [['inicio' => $inicio, 'fim' => $fim]],
    ], $escopo));
}

// Segunda-feira, 10h em Sao Paulo.
function segundaAs10(): Carbon
{
    return Carbon::parse('2026-08-03 10:00:00', 'America/Sao_Paulo');
}

it('sem grade de equipe, o comportamento e o de antes', function () {
    grade(1, '08:00', '18:00'); // conta

    $horas = new BusinessHours($this->tenant);

    expect($horas->abertoEm(segundaAs10(), $this->channel, $this->suporte))->toBeTrue()
        ->and($horas->abertoEm(segundaAs10()->setTime(20, 0), $this->channel, $this->suporte))->toBeFalse();
});

it('a grade da equipe prevalece sobre a do canal', function () {
    grade(1, '08:00', '18:00', ['channel_id' => $this->channel->id]);
    grade(1, '20:00', '22:00', ['team_id' => $this->suporte->id]);

    $horas = new BusinessHours($this->tenant);

    // 10h esta aberto pelo canal e fechado pela equipe. Quem manda e a equipe.
    expect($horas->abertoEm(segundaAs10(), $this->channel, $this->suporte))->toBeFalse()
        ->and($horas->abertoEm(segundaAs10()->setTime(21, 0), $this->channel, $this->suporte))->toBeTrue();

    // E sem equipe, o canal continua valendo.
    expect($horas->abertoEm(segundaAs10(), $this->channel))->toBeTrue();
});

it('o canal prevalece sobre a conta quando nao ha equipe', function () {
    grade(1, '08:00', '12:00');                                        // conta
    grade(1, '00:00', '23:59', ['channel_id' => $this->channel->id]);  // canal 24h

    $horas = new BusinessHours($this->tenant);

    expect($horas->abertoEm(segundaAs10()->setTime(15, 0), $this->channel))->toBeTrue()
        ->and($horas->abertoEm(segundaAs10()->setTime(15, 0)))->toBeFalse();
});

it('grade de equipe nao contamina a grade da conta', function () {
    // A consulta da conta era so whereNull('channel_id'). Sem incluir team_id, a
    // linha da equipe entraria na grade da conta e a conta herdaria horario de
    // equipe sem ninguem ter pedido.
    grade(1, '20:00', '22:00', ['team_id' => $this->suporte->id]);

    $horas = new BusinessHours($this->tenant);

    expect($horas->configurado())->toBeFalse()
        ->and($horas->abertoEm(segundaAs10()))->toBeTrue(); // inerte = sempre aberto
});

it('a resposta automatica usa o horario da equipe que recebeu a conversa', function () {
    Http::fake(['*' => fn () => Http::response(['key' => ['id' => 'AUTO-'.uniqid()]])]);
    Carbon::setTestNow(segundaAs10());

    $this->tenant->forceFill([
        'resposta_automatica_ativa' => true,
        'resposta_automatica_texto' => 'Estamos fechados. Voltamos {{proxima_abertura}}.',
    ])->save();

    grade(1, '08:00', '18:00');                                     // conta: aberta as 10h
    grade(1, '20:00', '22:00', ['team_id' => $this->suporte->id]);   // equipe: fechada as 10h

    $contato = Contact::create([
        'nome'          => 'Cliente',
        'telefone_e164' => '+5511999998888',
        'jid'           => '5511999998888@s.whatsapp.net',
    ]);

    $conversa = Conversation::abertaOuNova($this->channel->id, $contato->id);
    $conversa->update(['team_id' => $this->suporte->id]);

    $url = "/webhooks/evolution/{$this->channel->id}/{$this->channel->webhook_secret}";

    $this->postJson($url, [
        'event' => 'messages.upsert',
        'data'  => [
            'key'      => ['remoteJid' => '5511999998888@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSGEQ1'],
            'pushName' => 'Cliente',
            'message'  => ['conversation' => 'meu link caiu'],
            'messageTimestamp' => segundaAs10()->timestamp,
        ],
    ])->assertOk();

    // Pela conta estaria aberto e nada seria respondido. A automatica so existe
    // porque a grade da equipe venceu.
    $automatica = Message::where('conversation_id', $conversa->id)
        ->where('automatica', true)
        ->first();

    expect($automatica)->not->toBeNull()
        ->and($automatica->corpo)->toContain('20:00');
});

it('nao aceita duas grades da conta para o mesmo dia', function () {
    // A constraint antiga era unique (tenant_id, channel_id, dia_semana) e no
    // Postgres NULL nao colide com NULL: a grade da conta nunca esteve protegida.
    grade(1, '08:00', '12:00');

    expect(fn () => grade(1, '14:00', '18:00'))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('nao aceita uma linha pertencendo a canal e equipe ao mesmo tempo', function () {
    // Teste separado de proposito: violacao de constraint aborta a transacao
    // inteira no Postgres, nada roda depois dela no mesmo teste.
    expect(fn () => grade(1, '08:00', '12:00', [
        'channel_id' => $this->channel->id,
        'team_id'    => $this->suporte->id,
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
