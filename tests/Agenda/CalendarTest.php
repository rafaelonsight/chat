<?php
use App\Models\{AgendaProfessional,AgendaService,AgendaCalendarEntry,AgendaSettings,Tenant,User,Contact};
use App\Services\AgendaCalendar;
use App\Support\TenantContext;
use App\Filament\Pages\{AgendaInicio,AgendaConfiguracoes};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB,Notification};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function(){
 TenantContext::forget();Notification::fake();Filament::setCurrentPanel(Filament::getPanel('admin'));
 $this->travelTo(\Carbon\Carbon::parse('2026-10-13 08:00','America/Sao_Paulo'));
 $t=Tenant::create(['nome'=>'Salão sintético','slug'=>'calendar-test']);
 $this->admin=User::create(['tenant_id'=>$t->id,'name'=>'Administrador','email'=>'calendar-admin@example.test','password'=>'TestPassword123!','perfil'=>'admin']);$this->actingAs($this->admin);
 $this->p=AgendaProfessional::create(['nome'=>'Ana agenda','ativo'=>true]);
 $this->s=AgendaService::create(['nome'=>'Corte','preco'=>'50.00','duracao_minutos'=>30,'intervalo_minutos'=>15]);$this->p->syncServices([$this->s->id]);
 $this->calendar=app(AgendaCalendar::class);
 $rows=[];foreach(range(0,6) as $day){$rows[]=['weekday'=>$day,'start'=>'09:00','end'=>'12:00'];$rows[]=['weekday'=>$day,'start'=>'13:00','end'=>'18:00'];}
 $this->calendar->hours($this->admin,$this->p->id,$rows);
});
afterEach(fn()=>TenantContext::forget());
function calendarInput($test,string $start='2026-10-15 09:00'):array{return ['professional_id'=>$test->p->id,'service_id'=>$test->s->id,'client_name'=>'Cliente sintético','client_phone'=>'+5511999990000','start'=>$start];}
function calendarBook($test,string $start='2026-10-15 09:00'):AgendaCalendarEntry{return $test->calendar->book($test->admin,calendarInput($test,$start),(string)Str::uuid());}

it('uses UTC and includes the buffer in suggestions and authoritative occupancy',function(){
 $entry=calendarBook($this);
 expect($entry->fresh()->start_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-15 12:00')->and($entry->occupied_until->utc()->format('H:i'))->toBe('12:45');
 $slots=$this->calendar->slots($this->admin,$this->p->id,$this->s->id,'2026-10-15');
 expect($slots)->not->toContain('09:00','09:15','09:30','11:30','12:00')->toContain('09:45','13:00');
 expect(fn()=>calendarBook($this,'2026-10-15 09:30'))->toThrow(ValidationException::class);
 expect(calendarBook($this,'2026-10-15 09:45')->id)->not->toBe($entry->id);
});
it('does not invent availability outside work hours or on time off',function(){
 $this->calendar->hours($this->admin,$this->p->id,[]);
 expect($this->calendar->slots($this->admin,$this->p->id,$this->s->id,'2026-10-15'))->toBe([]);
 expect(fn()=>calendarBook($this))->toThrow(ValidationException::class);
});
it('puts blocks and bookings in the same exclusion constraint and frees cancelled blocks',function(){
 $block=$this->calendar->block($this->admin,$this->p->id,'2026-10-15 08:00','2026-10-15 18:00','Folga');
 expect(fn()=>calendarBook($this))->toThrow(ValidationException::class);
 $this->calendar->cancel($this->admin,$block->id,$block->version);
 $entry=calendarBook($this);
 expect(fn()=> $this->calendar->block($this->admin,$this->p->id,'2026-10-15 09:30','2026-10-15 10:30','Bloqueio'))->toThrow(ValidationException::class);
 $this->assertDatabaseCount('agenda_calendar_entries',2);
});
it('replays idempotent requests without duplicate contacts or reservations and rejects changed payloads',function(){
 $key=(string)Str::uuid();$input=calendarInput($this);$one=$this->calendar->book($this->admin,$input,$key);
 expect($this->calendar->book($this->admin,$input,$key)->id)->toBe($one->id);
 $input['start']='2026-10-15 10:00';expect(fn()=> $this->calendar->book($this->admin,$input,$key))->toThrow(ValidationException::class);
 $this->assertDatabaseCount('agenda_calendar_entries',1);$this->assertDatabaseCount('contacts',1);
});
it('preserves original terms and the original slot when a reschedule conflicts',function(){
 $entry=calendarBook($this);calendarBook($this,'2026-10-15 10:00');
 $this->s->update(['preco'=>'99.00','duracao_minutos'=>90]);
 expect(fn()=> $this->calendar->reschedule($this->admin,$entry->id,$entry->version,'2026-10-15 10:15'))->toThrow(ValidationException::class);
 expect($entry->fresh()->start_at->utc()->format('H:i'))->toBe('12:00')->and($entry->fresh()->version)->toBe(1);
 $new=$this->calendar->reschedule($this->admin,$entry->id,1,'2026-10-15 11:00');
 expect($new->price)->toBe('50.00')->and($new->duration_minutes)->toBe(30)->and($new->snapshot['service'])->toBe('Corte')->and($new->version)->toBe(2);
});
it('keeps future bookings inside the configured journey and rejects overlapping hours',function(){
 calendarBook($this);
 expect(fn()=> $this->calendar->hours($this->admin,$this->p->id,[]))->toThrow(ValidationException::class);
 expect(fn()=> $this->calendar->hours($this->admin,$this->p->id,[['weekday'=>4,'start'=>'09:00','end'=>'12:00'],['weekday'=>4,'start'=>'11:00','end'=>'18:00']]))->toThrow(ValidationException::class);
 expect(fn()=> $this->calendar->settings($this->admin,['timezone'=>'Europe/London']))->toThrow(ValidationException::class);
});
it('enforces lead times horizon cancellation deadlines and optimistic versions',function(){
 expect(fn()=>calendarBook($this,'2026-10-13 08:30'))->toThrow(ValidationException::class);
 expect(fn()=>calendarBook($this,'2027-03-13 09:00'))->toThrow(ValidationException::class);
 $entry=calendarBook($this);$updated=$this->calendar->presence($this->admin,$entry->id,1,'confirmada');
 expect(fn()=> $this->calendar->cancel($this->admin,$entry->id,1))->toThrow(ValidationException::class);
 $this->travelTo(\Carbon\Carbon::parse('2026-10-15 08:00','America/Sao_Paulo'));
 expect(fn()=> $this->calendar->cancel($this->admin,$entry->id,$updated->version))->toThrow(ValidationException::class);
 $this->calendar->settings($this->admin,['cancel_notice_minutes'=>0]);
 $this->calendar->cancel($this->admin,$entry->id,$updated->version);
 expect($entry->fresh()->active)->toBeFalse();
});
it('handles states without completing or marking a future visit as missed',function(){
 $entry=calendarBook($this);expect(fn()=> $this->calendar->state($this->admin,$entry->id,1,'concluido'))->toThrow(ValidationException::class);
 expect(fn()=> $this->calendar->state($this->admin,$entry->id,1,'falta'))->toThrow(ValidationException::class);
 $this->travelTo(\Carbon\Carbon::parse('2026-10-15 09:00','America/Sao_Paulo'));
 $working=$this->calendar->state($this->admin,$entry->id,1,'em_atendimento');
 expect($this->calendar->state($this->admin,$entry->id,$working->version,'concluido')->status)->toBe('concluido');
});
it('rejects nonexistent and ambiguous local wall times',function(){
 expect($this->calendar->local('2026-10-15 09:00','America/Sao_Paulo')->format('H:i'))->toBe('12:00');
 expect(fn()=> $this->calendar->local('2026-03-08 02:30','America/New_York'))->toThrow(ValidationException::class);
 expect(fn()=> $this->calendar->local('2026-11-01 01:30','America/New_York'))->toThrow(ValidationException::class);
});
it('limits professionals to their own records and optionally enables their own operations',function(){
 $user=User::create(['tenant_id'=>$this->admin->tenant_id,'name'=>'Ana','email'=>'professional-calendar@example.test','password'=>'TestPassword123!','perfil'=>'profissional']);$this->p->forceFill(['user_id'=>$user->id])->save();
 $own=calendarBook($this);$other=AgendaProfessional::create(['nome'=>'Outro profissional']);
 $this->actingAs($user);
 expect($this->calendar->entries($user)->pluck('id')->all())->toBe([$own->id])->and($this->calendar->canManage($user))->toBeFalse();
 expect(fn()=> $this->calendar->professional($user,$other->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 expect(fn()=> $this->calendar->cancel($user,$own->id,1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 $this->get('/admin/configuracoes-agenda')->assertForbidden();
 $this->actingAs($this->admin);$this->calendar->settings($this->admin,['professional_manage'=>true]);$this->actingAs($user);
 expect($this->calendar->presence($user,$own->id,1,'confirmada')->presence)->toBe('confirmada');
 expect(fn()=> $this->calendar->hours($user,$this->p->id,[]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('rejects cross-tenant services contacts and raw SQL links',function(){
 $other=Tenant::create(['nome'=>'Outro estabelecimento','slug'=>'calendar-other']);
 $service=TenantContext::runAs($other->id,fn()=>AgendaService::create(['nome'=>'Outro serviço','preco'=>1,'duracao_minutos'=>30,'intervalo_minutos'=>0]));
 $contact=Contact::create(['tenant_id'=>$other->id,'nome'=>'Outro cliente','telefone_e164'=>'+5511999997777']);
 $input=calendarInput($this);$input['contact_id']=$contact->id;
 expect(fn()=> $this->calendar->book($this->admin,$input,(string)Str::uuid()))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
 $entry=calendarBook($this);
 expect(fn()=>DB::transaction(fn()=>DB::table('agenda_calendar_entries')->where('id',$entry->id)->update(['service_id'=>$service->id])))->toThrow(\Illuminate\Database\QueryException::class);
});
it('uses actual administrator settings and reservation forms in daily and weekly views',function(){
 Livewire::test(AgendaConfiguracoes::class)->set('settings.slot_minutes',30)->call('saveSettings')->assertHasNoErrors();
 expect(AgendaSettings::current()->slot_minutes)->toBe(30);
 Livewire::test(AgendaInicio::class)->call('openNew')->set('form.professional_id',$this->p->id)->set('form.service_id',$this->s->id)->set('form.client_name','Cliente do painel')->set('form.client_phone','+5511999992222')->set('form.date','2026-10-15')->set('form.time','09:00')->call('saveEntry')->assertHasNoErrors()->assertSet('editor',false);
 Livewire::test(AgendaInicio::class)->set('date','2026-10-15')->set('mode','week')->assertSee('Cliente do painel');
 $this->assertDatabaseCount('agenda_calendar_entries',1);
});

it('saves split work hours and rejects nonadministrative configuration attempts',function(){
 Livewire::test(AgendaConfiguracoes::class)->set('professional',(string)$this->p->id)->call('loadHours')->set('hours',[['weekday'=>4,'start'=>'10:00','end'=>'12:00'],['weekday'=>4,'start'=>'13:00','end'=>'17:00']])->call('saveHours')->assertHasNoErrors();
 expect($this->calendar->slots($this->admin,$this->p->id,$this->s->id,'2026-10-15'))->not->toContain('09:00','12:00')->toContain('10:00','13:00');
 $reception=User::create(['tenant_id'=>$this->admin->tenant_id,'name'=>'Recepção','email'=>'reception-calendar@example.test','password'=>'TestPassword123!','perfil'=>'atendente']);$this->actingAs($reception);
 expect($this->calendar->canManage($reception))->toBeTrue();
 expect(fn()=> $this->calendar->settings($reception,['min_notice_minutes'=>0]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
 $this->get('/admin/configuracoes-agenda')->assertForbidden();
});
it('keeps a conflicting booking form open and allows consultation without reservation',function(){
 calendarBook($this);
 $component=Livewire::test(AgendaInicio::class)->call('openNew')->set('form.professional_id',$this->p->id)->set('form.service_id',$this->s->id)->set('form.date','2026-10-15')->set('form.time','09:00')->set('form.client_name','Outro cliente')->set('form.client_phone','+5511999993333')->call('suggest')->assertHasNoErrors();
 $this->assertDatabaseCount('agenda_calendar_entries',1);
 $component->call('saveEntry')->assertHasErrors(['form.agenda'])->assertSet('editor',true)->assertSet('form.client_name','Outro cliente');
});
it('keeps a professional from tampering with filters actions or the colleague history',function(){
 $user=User::create(['tenant_id'=>$this->admin->tenant_id,'name'=>'Ana','email'=>'own-calendar@example.test','password'=>'TestPassword123!','perfil'=>'profissional']);$this->p->forceFill(['user_id'=>$user->id])->save();
 $other=AgendaProfessional::create(['nome'=>'Colega reservado']);$other->syncServices([$this->s->id]);
 $this->calendar->hours($this->admin,$other->id,[['weekday'=>4,'start'=>'09:00','end'=>'18:00']]);
 $input=calendarInput($this);$input['professional_id']=$other->id;$input['client_phone']='+5511999994444';$input['client_name']='Cliente reservado';
 $entry=$this->calendar->book($this->admin,$input,(string)Str::uuid());
 $this->actingAs($user);
 expect($this->calendar->clientHistory($user,$entry->contact_id))->toBe([]);
 Livewire::test(AgendaInicio::class)->set('date','2026-10-15')->assertDontSee('Colega reservado')->assertDontSee('Cliente reservado')->call('openNew')->assertForbidden();
 expect(fn()=> $this->calendar->entry($user,$entry->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
it('does not allow malformed settings or entries without an authorized tenant',function(){
 expect(fn()=> $this->calendar->settings($this->admin,['timezone'=>'Made/Up']))->toThrow(ValidationException::class);
 expect(fn()=> $this->calendar->settings($this->admin,['slot_minutes'=>0]))->toThrow(ValidationException::class);
 auth()->logout();TenantContext::forget();expect(AgendaCalendarEntry::count())->toBe(0);
});
it('supports midnight shift endings without accepting invalid clock values',function(){
 $this->calendar->hours($this->admin,$this->p->id,[['weekday'=>4,'start'=>'20:00','end'=>'24:00']]);
 expect($this->calendar->slots($this->admin,$this->p->id,$this->s->id,'2026-10-15'))->toContain('23:15')->not->toContain('23:30');
 expect(fn()=> $this->calendar->hours($this->admin,$this->p->id,[['weekday'=>4,'start'=>'20:00','end'=>'24:30']]))->toThrow(ValidationException::class);
});
