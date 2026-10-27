<?php
use App\Models\{Tenant,User,AgendaChannelConnection as Connection};
use App\Services\AgendaEvolution;
use App\Filament\Pages\AgendaWhatsAppQr;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Filament\Facades\Filament;
use Livewire\Livewire;
beforeEach(function(){TenantContext::forget();Filament::setCurrentPanel(Filament::getPanel('admin'));$t=Tenant::create(['nome'=>'QR sintético','slug'=>'qr-test']);$this->admin=User::create(['tenant_id'=>$t->id,'name'=>'Admin','email'=>'qr-admin@example.test','password'=>'TestPassword123!','perfil'=>'admin']);$this->actingAs($this->admin);config(['agenda.evolution_enabled'=>true,'agenda.evolution_url'=>'http://127.0.0.1:8085','agenda.evolution_key'=>'synthetic-key']);$this->qr='data:image/png;base64,'.base64_encode('synthetic-png');});
afterEach(fn()=>TenantContext::forget());
it('opens directly on the QR without name token or credential form',function(){
 Http::fake(['*/instance/connectionState/*'=>Http::response(['instance'=>['state'=>'connecting']]),'*/instance/connect/*'=>Http::response(['base64'=>$this->qr])]);
 Livewire::test(AgendaWhatsAppQr::class)->assertSet('qr',$this->qr)->assertSet('state','connecting')->assertSee('Aparelhos conectados');
 $this->assertDatabaseCount('agenda_channel_connections',1);$c=Connection::firstOrFail();expect($c->type)->toBe('evolution')->and($c->status)->toBe('conectando');
 app(AgendaEvolution::class)->channel($this->admin);$this->assertDatabaseCount('agenda_channel_connections',1);
 Http::assertSent(fn($r)=>str_contains($r->url(),'/agenda-'.str_replace('-','',$c->public_id)) && $r->hasHeader('apikey','synthetic-key'));
});
it('creates only the Agenda instance when it does not exist',function(){
 $this->created=false;Http::fake(['*'=>function($r){if($r->method()==='POST'){$this->created=true;expect($r['integration'])->toBe('WHATSAPP-BAILEYS');return Http::response([],201);}if(str_contains($r->url(),'connectionState'))return $this->created?Http::response(['instance'=>['state'=>'connecting']]):Http::response([],404);return Http::response(['base64'=>$this->qr]);}]);
 Livewire::test(AgendaWhatsAppQr::class)->assertSet('qr',$this->qr);expect($this->created)->toBeTrue();
});
it('only marks connected after provider confirms open and removes the QR',function(){
 Http::fake(['*'=>Http::response(['instance'=>['state'=>'open']])]);Livewire::test(AgendaWhatsAppQr::class)->assertSet('state','open')->assertSet('qr',null);expect(Connection::firstOrFail()->status)->toBe('conectado');Http::assertSentCount(1);
});
it('refuses another tenant or professional and blocks arbitrary Evolution URLs',function(){
 $c=app(AgendaEvolution::class)->channel($this->admin);$t=Tenant::create(['nome'=>'Outro','slug'=>'qr-other']);$u=User::create(['tenant_id'=>$t->id,'name'=>'Other','email'=>'qr-other@example.test','password'=>'TestPassword123!','perfil'=>'admin']);$this->actingAs($u);Http::fake();
 expect(fn()=>app(AgendaEvolution::class)->refresh($u,$c->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);Http::assertNothingSent();
 $this->actingAs($this->admin);config(['agenda.evolution_url'=>'http://127.0.0.1:8084']);expect(fn()=>app(AgendaEvolution::class)->refresh($this->admin,$c->id))->toThrow(\Illuminate\Validation\ValidationException::class);Http::assertNothingSent();
});
it('does not display provider exceptions or arbitrary image URLs',function(){
 Http::fake(['*/instance/connectionState/*'=>Http::response(['instance'=>['state'=>'connecting']]),'*/instance/connect/*'=>Http::response(['base64'=>'https://evil.test/image'])]);Livewire::test(AgendaWhatsAppQr::class)->assertSet('qr',null)->assertDontSee('evil.test');
});
it('logs out only its own instance and invalidates the connection version',function(){
 $c=app(AgendaEvolution::class)->channel($this->admin);Http::fake(['*'=>Http::response([])]);app(AgendaEvolution::class)->disconnect($this->admin,$c->id);
 expect($c->fresh()->status)->toBe('desconectado')->and($c->fresh()->version)->toBe(2);Http::assertSent(fn($r)=>$r->method()==='DELETE' && str_contains($r->url(),'/instance/logout/agenda-'));
});
