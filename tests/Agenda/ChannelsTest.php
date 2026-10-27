<?php
use App\Models\{AgendaChannelConnection as Connection,Tenant,User,AgendaProfessional};
use App\Services\{AgendaChannels,AgendaPlan,AgendaAccess};
use App\Filament\Pages\AgendaCanais;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB,Http,Notification};
use Illuminate\Validation\ValidationException;
use Filament\Facades\Filament;
use Livewire\Livewire;
beforeEach(function(){
 TenantContext::forget();Notification::fake();Filament::setCurrentPanel(Filament::getPanel('admin'));
 $tenant=Tenant::create(['nome'=>'Canais sintéticos','slug'=>'channels-test']);
 $this->admin=User::create(['tenant_id'=>$tenant->id,'name'=>'Administrador','email'=>'admin-channels@example.test','password'=>'OnlyForTests123!','perfil'=>'admin']);$this->actingAs($this->admin);$this->channels=app(AgendaChannels::class);
});
afterEach(fn()=>TenantContext::forget());
function channelData(array $values=[]):array{return array_merge(['name'=>'Conexão sintética','external_id'=>'1234567890','business_id'=>'9876543210','token'=>'synthetic-token-not-a-real-secret','app_secret'=>'synthetic-app-secret'], $values);}
it('renders four optional channels and the agreed monthly plan',function(){
 $this->get('/admin/canais')->assertOk()->assertSee('Conectar por QR Code')->assertSee('WhatsApp oficial')->assertSee('WhatsApp não oficial')->assertSee('Instagram')->assertSee('Facebook');
 expect(AgendaPlan::BASE_CENTS)->toBe(24990)->and(AgendaPlan::EXTRA_CENTS)->toBe(1990);
 expect(app(AgendaPlan::class)->summary($this->admin->tenant_id)['user_limit'])->toBe(5);
 $this->assertDatabaseCount('agenda_channel_connections',0);
});
it('saves through the actual panel without disclosing saved credentials or claiming connectivity',function(){
 $this->channels->save($this->admin,'meta_cloud',channelData());
 $c=Connection::firstOrFail();expect($c->status)->toBe('configurado')->and($c->credentials['token'])->toBe('synthetic-token-not-a-real-secret');
 expect(DB::table('agenda_channel_connections')->value('credentials'))->not->toContain('synthetic-token-not-a-real-secret');
 expect($c->toArray())->not->toHaveKey('credentials');
 Livewire::test(AgendaCanais::class)->assertDontSee('synthetic-token-not-a-real-secret')->assertDontSee('Phone Number ID')->assertDontSee('App Secret')->assertSee('Entrar com Instagram');
});
it('reconfigures the same slot and preserves the token only while explicitly left blank',function(){
 $c=$this->channels->save($this->admin,'meta_cloud',channelData());
 $again=$this->channels->save($this->admin,'meta_cloud',channelData(['name'=>'Novo nome','token'=>'','app_secret'=>'']));
 expect($again->id)->toBe($c->id)->and($again->version)->toBe(2)->and($again->credentials['token'])->toBe('synthetic-token-not-a-real-secret');$this->assertDatabaseCount('agenda_channel_connections',1);
 $this->channels->disconnect($this->admin,$c->id);
 expect($c->fresh()->status)->toBe('desconectado')->and($c->fresh()->credentials)->toBeNull();
 expect(fn()=> $this->channels->save($this->admin,'meta_cloud',channelData(['token'=>''])))->toThrow(ValidationException::class);
});
it('blocks cross-account access and nonadministrator operations',function(){
 $c=$this->channels->save($this->admin,'meta_cloud',channelData());
 $tenant=Tenant::create(['nome'=>'Outra conta','slug'=>'channels-other']);
 $other=User::create(['tenant_id'=>$tenant->id,'name'=>'Outro admin','email'=>'other-channels@example.test','password'=>'OnlyForTests123!','perfil'=>'admin']);$this->actingAs($other);
 expect(fn()=> $this->channels->disconnect($other,$c->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
 $reception=User::create(['tenant_id'=>$tenant->id,'name'=>'Recepção','email'=>'reception-channels@example.test','password'=>'OnlyForTests123!','perfil'=>'atendente']);$this->actingAs($reception);
 $this->get('/admin/canais')->assertForbidden();expect(fn()=> $this->channels->save($reception,'instagram',channelData()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('refuses extra channel types and accounts already assigned elsewhere in the installation',function(){
 $this->channels->save($this->admin,'meta_cloud',channelData());
 expect(fn()=> $this->channels->save($this->admin,'site',channelData()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 $tenant=Tenant::create(['nome'=>'Outra conta','slug'=>'channels-collision']);$other=User::create(['tenant_id'=>$tenant->id,'name'=>'Admin','email'=>'collision-channels@example.test','password'=>'OnlyForTests123!','perfil'=>'admin']);$this->actingAs($other);
 expect(fn()=> $this->channels->save($other,'meta_cloud',channelData()))->toThrow(ValidationException::class);
 $this->assertDatabaseCount('agenda_channel_connections',1);
});
it('does not send external requests when provider activation is pending',function(){
 Http::fake();$c=$this->channels->save($this->admin,'meta_cloud',channelData());
 expect(fn()=> $this->channels->validate($this->admin,$c->id))->toThrow(ValidationException::class);
 Http::assertNothingSent();expect($c->fresh()->status)->toBe('configurado');
});
it('validates both the phone identity and its WABA membership using its own bearer token',function(){
 config(['agenda.channels_enabled'=>true]);$c=$this->channels->save($this->admin,'meta_cloud',channelData());
 Http::fake(['graph.facebook.com/*/1234567890*'=>Http::response(['id'=>'1234567890','display_phone_number'=>'+5511999990000']), 'graph.facebook.com/*/9876543210/phone_numbers*'=>Http::response(['data'=>[['id'=>'1234567890']]])]);
 expect($this->channels->validate($this->admin,$c->id))->toBeTrue()->and($c->fresh()->status)->toBe('validado')->and($c->fresh()->received_at)->toBeNull();
 Http::assertSent(fn($r)=>$r->hasHeader('Authorization','Bearer synthetic-token-not-a-real-secret') && !str_contains($r->url(),'synthetic-token'));
});
it('records a sanitized failure without saving the provider error or credential',function(){
 config(['agenda.channels_enabled'=>true]);$c=$this->channels->save($this->admin,'messenger',channelData());
 Http::fake(['*'=>Http::response(['error'=>['message'=>'synthetic-token-not-a-real-secret']],401)]);
 expect($this->channels->validate($this->admin,$c->id))->toBeFalse()->and($c->fresh()->failure_code)->toBe('PROVIDER_VALIDATION_FAILED');
 expect(json_encode(DB::table('agenda_channel_events')->get()))->not->toContain('synthetic-token');
});
it('requires a dedicated Evolution manager and never treats QR as Meta',function(){
 Http::fake();config(['agenda.channels_enabled'=>true]);$c=$this->channels->save($this->admin,'evolution',['name'=>'QR sintético']);
 expect(fn()=> $this->channels->validate($this->admin,$c->id))->toThrow(ValidationException::class);Http::assertNothingSent();
});
it('enforces five users atomically and permits only provisioned additional seats',function(){
 foreach(range(2,5) as $i)User::create(['tenant_id'=>$this->admin->tenant_id,'name'=>'User '.$i,'email'=>'seat-'.$i.'@example.test','password'=>'OnlyForTests123!','perfil'=>'atendente']);
 expect(fn()=> User::create(['tenant_id'=>$this->admin->tenant_id,'name'=>'Six','email'=>'seat-six@example.test','password'=>'OnlyForTests123!']))->toThrow(ValidationException::class);
 expect(fn()=> app(AgendaAccess::class)->createProfessional($this->admin,['nome'=>'Sem vaga','email'=>'no-seat@example.test']))->toThrow(ValidationException::class);$this->assertDatabaseCount('agenda_professionals',0);
 DB::table('agenda_plan_entitlements')->insert(['tenant_id'=>$this->admin->tenant_id,'extra_users'=>1,'reference'=>'SYNTHETIC-ENTITLEMENT','created_at'=>now(),'updated_at'=>now()]);
 User::create(['tenant_id'=>$this->admin->tenant_id,'name'=>'Six','email'=>'seat-six@example.test','password'=>'OnlyForTests123!']);
 $plan=app(AgendaPlan::class)->summary($this->admin->tenant_id);expect($plan['users'])->toBe(6)->and($plan['total_cents'])->toBe(26980);
});
it('does not charge a second seat for an existing administrator also working as a professional',function(){
 app(AgendaAccess::class)->createProfessional($this->admin,['nome'=>'Admin profissional'],'existing',$this->admin->id);
 expect(app(AgendaPlan::class)->summary($this->admin->tenant_id)['users'])->toBe(1);
});
