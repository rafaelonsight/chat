<?php
use App\Models\{Tenant,Channel,Contact,Conversation};
use App\Services\ProtocoloAtendimento;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function(){
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00','UTC'));
    $this->conta=Tenant::create(['nome'=>'A','slug'=>'protocolo-curto','fuso_horario'=>'Europe/London']);
    TenantContext::set($this->conta->id);
});
afterEach(function(){Carbon::setTestNow();TenantContext::forget();});
it('começa em VT-2601 e incrementa por conta e por ano',function(){
    $s=app(ProtocoloAtendimento::class);
    expect($s->gerar($this->conta->id))->toBe('VT-2601')->and($s->gerar($this->conta->id))->toBe('VT-2602');
    $outra=Tenant::create(['nome'=>'B','slug'=>'outra-protocolo']);
    expect($s->gerar($outra->id))->toBe('VT-2601');
    Carbon::setTestNow('2027-01-02 12:00:00');
    expect($s->gerar($this->conta->id))->toBe('VT-2701');
});
it('cresce além de dois dígitos sem truncar o número',function(){
    DB::table('sequencias_protocolo')->insert(['tenant_id'=>$this->conta->id,'ano'=>2026,'ultimo'=>99]);
    expect(app(ProtocoloAtendimento::class)->gerar($this->conta->id))->toBe('VT-26100');
});
it('respeita o ano no fuso da empresa',function(){
    $this->conta->update(['fuso_horario'=>'America/Sao_Paulo']);
    Carbon::setTestNow(Carbon::parse('2027-01-01 01:00:00','UTC'));
    expect(app(ProtocoloAtendimento::class)->gerar($this->conta->id))->toBe('VT-2601');
});
it('emite o protocolo na criação e preserva protocolos antigos',function(){
    $canal=Channel::create(['nome'=>'Canal']);$contato=Contact::create(['nome'=>'Contato','jid'=>'551100000000@s.whatsapp.net']);
    $c=Conversation::create(['channel_id'=>$canal->id,'contact_id'=>$contato->id]);
    expect($c->protocolo)->toBe('VT-2601')->and($c->refresh()->protocolo)->toBe('VT-2601');
    $c->forceFill(['protocolo'=>'VRT-20261008-000041'])->saveQuietly();
    $c->update(['nao_lidas'=>1]);
    expect($c->refresh()->protocolo)->toBe('VRT-20261008-000041');
});
it('permite o mesmo código em contas distintas sem perder unicidade em cada conta',function(){
    foreach([$this->conta,Tenant::create(['nome'=>'B','slug'=>'conta-b-protocolo'])] as $t){
        TenantContext::runAs($t->id,function(){
            $canal=Channel::create(['nome'=>'Canal']);$contato=Contact::create(['nome'=>'Cliente','jid'=>'551100000000@s.whatsapp.net']);
            $c=Conversation::create(['channel_id'=>$canal->id,'contact_id'=>$contato->id]);
            expect($c->protocolo)->toBe('VT-2601');
        });
    }
    expect(Conversation::withoutGlobalScopes()->where('protocolo','VT-2601')->count())->toBe(2);
});
