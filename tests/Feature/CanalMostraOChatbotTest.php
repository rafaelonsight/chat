<?php

use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\{Channel, Chatbot, Tenant, User};
use App\Support\TenantContext;
use Livewire\Livewire;

/*
 * Qual fluxo atende cada canal, visivel na lista.
 *
 * Isto existe por causa de uma investigacao real: o chatbot nao respondeu no canal oficial
 * e ninguem tinha como saber por que — o fluxo estava amarrado ao outro canal, e a tela nao
 * dizia nada. A resposta exigiu consulta no banco. Uma vez basta.
 */

beforeEach(function () {
    $this->tenant = Tenant::create(['nome' => 'T', 'slug' => 'botcanal']);
    TenantContext::set($this->tenant->id);

    $this->usuario = User::create([
        'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@bc.test',
        'password' => 'segredo123', 'admin' => true,
    ]);

    \Filament\Facades\Filament::setCurrentPanel('admin');
});

afterEach(fn () => TenantContext::forget());

it('mostra o nome do fluxo que atende o canal', function () {
    $canal = Channel::create(['nome' => 'Pessoal'])->refresh();

    Chatbot::create([
        'nome' => 'Recepção', 'ativo' => true, 'status' => Chatbot::PUBLICADO,
        'channel_id' => $canal->id, 'mensagem_boas_vindas' => 'oi',
        'mensagem_nao_entendi' => 'nao entendi', 'max_tentativas' => 2,
        'palavra_escape' => 'atendente', 'tolerancia_segundos' => 0,
    ]);

    Livewire::actingAs($this->usuario)
        ->test(ListChannels::class)
        ->assertSee('Recepção');
});

it('diz NENHUM quando ninguem atende automaticamente naquele canal', function () {
    // Era exatamente o caso do canal oficial: bot publicado existia, mas amarrado ao outro
    // canal, e a tela nao dava nenhuma pista.
    $oficial = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111', 'meta_waba_id' => '362',
    ])->refresh();

    Chatbot::create([
        'nome' => 'Recepção', 'ativo' => true, 'status' => Chatbot::PUBLICADO,
        'channel_id' => Channel::create(['nome' => 'Outro'])->id,
        'mensagem_boas_vindas' => 'oi', 'mensagem_nao_entendi' => 'nao entendi',
        'max_tentativas' => 2, 'palavra_escape' => 'atendente', 'tolerancia_segundos' => 0,
    ]);

    Livewire::actingAs($this->usuario)
        ->test(ListChannels::class)
        ->assertSee('nenhum');

    expect(Chatbot::publicadoPara($oficial))->toBeNull();
});

it('fluxo sem canal atende qualquer canal', function () {
    // channel_id nulo e o "todos": e a saida para quem tem um fluxo so.
    $oficial = Channel::create([
        'nome' => 'Oficial', 'tipo' => Channel::META_CLOUD,
        'meta_phone_number_id' => '111', 'meta_waba_id' => '362',
    ])->refresh();

    Chatbot::create([
        'nome' => 'Geral', 'ativo' => true, 'status' => Chatbot::PUBLICADO,
        'channel_id' => null, 'mensagem_boas_vindas' => 'oi',
        'mensagem_nao_entendi' => 'nao entendi', 'max_tentativas' => 2,
        'palavra_escape' => 'atendente', 'tolerancia_segundos' => 0,
    ]);

    expect(Chatbot::publicadoPara($oficial)?->nome)->toBe('Geral');
});
