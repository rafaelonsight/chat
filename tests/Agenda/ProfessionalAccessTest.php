<?php

use App\Models\{AgendaProfessional, AgendaService, Tenant, User};
use App\Services\AgendaAccess;
use App\Support\TenantContext;
use App\Filament\Pages\Auth\{Login, ResetPassword};
use App\Filament\Resources\Agenda\Professionals\Pages\{CreateProfessional, EditProfessional};
use App\Notifications\AgendaAccessInvitation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{DB, Hash, Notification, Password, RateLimiter};
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::forget();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Notification::fake();
    $tenant=Tenant::create(['nome'=>'Salão de teste','slug'=>'access-test']);
    $this->admin=User::create(['tenant_id'=>$tenant->id,'name'=>'Administrador','email'=>'admin@access.test','password'=>'TestPassword123!','perfil'=>'admin']);
    $this->actingAs($this->admin);
    $this->access=app(AgendaAccess::class);
});
afterEach(fn () => TenantContext::forget());

function accessProfessional($test, string $email='professional@access.test'): AgendaProfessional {
    return $test->access->createProfessional($test->admin,['nome'=>'Ana profissional','email'=>$email,'cor'=>'#6338F6','ativo'=>true]);
}

it('creates a professional identity through the real panel and selects services', function () {
    $service=AgendaService::create(['nome'=>'Corte exclusivo','preco'=>50,'duracao_minutos'=>30,'intervalo_minutos'=>10]);
    Livewire::test(CreateProfessional::class)->fillForm(['nome'=>'Ana profissional','email'=>'ANA@access.test','cor'=>'#6338F6','ativo'=>true,'services'=>[$service->id],'access_mode'=>'new'])->call('create')->assertHasNoFormErrors();
    $professional=AgendaProfessional::firstOrFail();$user=$this->access->userFor($professional);
    expect($user->email)->toBe('ana@access.test')->and($user->perfil)->toBe('profissional')->and($user->admin)->toBeFalse()->and($user->agenda_access_status)->toBe('convite_pendente')->and($user->canEnterAgenda())->toBeFalse();
    expect($professional->services->modelKeys())->toBe([$service->id]);
    $delivery=$this->access->latestDelivery($professional);
    $this->access->deliver($delivery->id);
    Notification::assertSentTo($user,AgendaAccessInvitation::class);
    expect($this->access->latestDelivery($professional)->status)->toBe('aceito');
    $metadata=DB::table('agenda_access_events')->value('metadata');
    expect($metadata)->not->toContain('password','token');
});

it('preserves administrator permissions when explicitly linking an existing account', function () {
    $hash=$this->admin->password;
    $professional=$this->access->createProfessional($this->admin,['nome'=>'Administrador profissional'],'existing',$this->admin->id);
    expect($professional->user_id)->toBe($this->admin->id)->and($this->admin->fresh()->password)->toBe($hash)->and($this->admin->fresh()->admin)->toBeTrue();
    expect($this->access->latestDelivery($professional))->toBeNull();
    expect(fn () => $this->access->manage($this->admin,$professional,'suspend'))->toThrow(ValidationException::class);
});

it('rolls back duplicated emails and cross-account identity links', function () {
    accessProfessional($this);
    expect(fn () => accessProfessional($this,'PROFESSIONAL@access.test'))->toThrow(ValidationException::class);
    $tenant=Tenant::create(['nome'=>'Outro salão','slug'=>'other-access']);
    $other=User::create(['tenant_id'=>$tenant->id,'name'=>'Outro usuário','email'=>'other@access.test','password'=>'TestPassword123!','perfil'=>'atendente']);
    expect(fn () => $this->access->createProfessional($this->admin,['nome'=>'Inválido'],'existing',$other->id))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('agenda_professionals',1);
});

it('adds access to a legacy professional using the edit form', function () {
    $professional=AgendaProfessional::create(['nome'=>'Sem acesso']);
    Livewire::test(EditProfessional::class,['record'=>$professional->id])->fillForm(['email'=>'legacy@access.test','access_mode'=>'new'])->call('save')->assertHasNoFormErrors();
    expect($professional->fresh()->user_id)->not->toBeNull();
});

it('activates the invitation with an eight-character password and consumes the link', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $token=Password::broker()->createToken($user);
    auth()->logout();TenantContext::forget();
    Livewire::test(ResetPassword::class,['email'=>$user->email,'token'=>$token])->fillForm(['password'=>'abcdefgh','passwordConfirmation'=>'abcdefgh'])->call('resetPassword')->assertHasNoFormErrors();
    expect($user->fresh()->agenda_access_status)->toBe('ativo')->and(Hash::check('abcdefgh',$user->fresh()->password))->toBeTrue()->and(Password::broker()->tokenExists($user,$token))->toBeFalse();
    Livewire::test(Login::class)->fillForm(['email'=>$user->email,'password'=>'abcdefgh'])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);
});

it('rejects login before password activation', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $user->forceFill(['password'=>'TestPassword123!'])->save();
    auth()->logout();TenantContext::forget();
    Livewire::test(Login::class)->fillForm(['email'=>$user->email,'password'=>'TestPassword123!'])->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
});

it('revokes sessions tokens and passwords when suspending and requires a fresh password on reactivation', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $this->access->completePassword($user,'abcdefgh');$user=$user->fresh();
    $token=Password::broker()->createToken($user);$version=$user->agenda_access_version;
    DB::table('sessions')->insert(['id'=>'professional-session','user_id'=>$user->id,'payload'=>'test','last_activity'=>time()]);
    $this->access->manage($this->admin,$professional,'suspend');
    expect($user->fresh()->agenda_access_status)->toBe('suspenso')->and($user->fresh()->agenda_access_version)->toBe($version+1)->and(Hash::check('abcdefgh',$user->fresh()->password))->toBeFalse()->and(Password::broker()->tokenExists($user,$token))->toBeFalse();
    $this->assertDatabaseMissing('sessions',['id'=>'professional-session']);
    $this->access->manage($this->admin,$professional,'reactivate');
    expect($user->fresh()->agenda_access_status)->toBe('redefinicao_obrigatoria')->and($user->fresh()->canEnterAgenda())->toBeFalse();
});

it('changes the login email revoking the previous recovery token without reactivating suspended access', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $token=Password::broker()->createToken($user);
    $this->access->manage($this->admin,$professional,'change_email','NEW@access.test');
    expect($user->fresh()->email)->toBe('new@access.test')->and($professional->fresh()->email)->toBe('new@access.test')->and(Password::broker()->tokenExists($user,$token))->toBeFalse();
    $this->access->manage($this->admin,$professional,'suspend');
    $last=$this->access->latestDelivery($professional)->id;
    $this->access->manage($this->admin,$professional,'change_email','suspended@access.test');
    expect($user->fresh()->agenda_access_status)->toBe('suspenso')->and($this->access->latestDelivery($professional)->id)->toBe($last);
});

it('keeps the registration when the mail transport is unavailable and supersedes revoked invitations', function () {
    config(['mail.default'=>'log']);
    $professional=accessProfessional($this);$delivery=$this->access->latestDelivery($professional);
    config(['mail.default'=>'log']);$this->access->deliver($delivery->id);
    expect($this->access->latestDelivery($professional)->status)->toBe('falhou');
    Notification::assertNothingSent();
    $this->access->manage($this->admin,$professional,'suspend');
    $this->access->manage($this->admin,$professional,'reactivate');
    $delivery=$this->access->latestDelivery($professional);
    $this->access->manage($this->admin,$professional,'suspend');
    $this->access->deliver($delivery->id);
    expect($this->access->latestDelivery($professional)->status)->toBe('substituido');
});

it('isolates the professional workspace and rejects administrative routes and stale sessions', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $service=AgendaService::create(['nome'=>'Serviço próprio','preco'=>50,'duracao_minutos'=>30,'intervalo_minutos'=>0]);$professional->syncServices([$service->id]);
    AgendaProfessional::create(['nome'=>'Outro profissional secreto']);
    $this->access->completePassword($user,'abcdefgh');$user=$user->fresh();
    $this->actingAs($user)->withSession(['agenda.access_version'=>$user->agenda_access_version]);
    $this->get('/admin')->assertOk()->assertSee('Minha agenda')->assertSee('Serviço próprio')->assertDontSee('Outro profissional secreto');
    foreach(['/admin/profissionais','/admin/servicos','/admin/users','/admin/contacts'] as $url)$this->get($url)->assertForbidden();
    $this->withSession(['agenda.access_version'=>$user->agenda_access_version-1])->get('/admin')->assertRedirect('/admin/login');
});

it('protects the last active administrator and rejects professional management privileges', function () {
    expect(fn () => $this->admin->forceFill(['perfil'=>'atendente'])->save())->toThrow(ValidationException::class);
    $this->admin->refresh();$professional=accessProfessional($this);$user=$this->access->userFor($professional);
    expect(fn () => $this->access->manage($user,$professional,'suspend'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('allows the actual administrator action to suspend access', function () {
    $professional=accessProfessional($this);
    Livewire::test(EditProfessional::class,['record'=>$professional->id])->callAction('suspend')->assertHasNoActionErrors();
    expect($this->access->userFor($professional)->agenda_access_status)->toBe('suspenso');
});

it('rejects short passwords and expired recovery links on the actual reset page', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);$token=Password::broker()->createToken($user);
    auth()->logout();TenantContext::forget();
    Livewire::test(ResetPassword::class,['email'=>$user->email,'token'=>$token])->fillForm(['password'=>'abcdefg','passwordConfirmation'=>'abcdefg'])->call('resetPassword')->assertHasFormErrors(['password']);
    DB::table('password_reset_tokens')->where('email',$user->email)->update(['created_at'=>now()->subMinutes(61)]);
    Livewire::test(ResetPassword::class,['email'=>$user->email,'token'=>$token])->fillForm(['password'=>'abcdefgh','passwordConfirmation'=>'abcdefgh'])->call('resetPassword');
    expect($user->fresh()->agenda_access_status)->toBe('convite_pendente');
});

it('does not let a suspended professional define a password even with a new token', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $this->access->manage($this->admin,$professional,'suspend');
    expect(fn () => $this->access->completePassword($user,'abcdefgh'))->toThrow(ValidationException::class);
    expect($user->fresh()->agenda_access_status)->toBe('suspenso');
});

it('changes the own password while invalidating other sessions and retaining the current access', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $this->access->completePassword($user,'abcdefgh');$user=$user->fresh();
    $this->actingAs($user)->withSession(['agenda.access_version'=>$user->agenda_access_version]);
    DB::table('sessions')->insert(['id'=>'old-profile-session','user_id'=>$user->id,'payload'=>'test','last_activity'=>time()]);
    Livewire::test(\App\Filament\Pages\Auth\Perfil::class)->fillForm(['name'=>$user->name,'apelido'=>'Ana','telefone'=>'11999990000','password'=>'ijklmnop','passwordConfirmation'=>'ijklmnop','currentPassword'=>'abcdefgh'])->call('save')->assertHasNoFormErrors();
    expect(Hash::check('ijklmnop',$user->fresh()->password))->toBeTrue()->and($user->fresh()->agenda_access_version)->toBe($user->agenda_access_version+1);
    $this->assertDatabaseMissing('sessions',['id'=>'old-profile-session']);
    $this->get('/admin')->assertOk();
});

it('enforces professional identity isolation and unique links in PostgreSQL', function () {
    $professional=accessProfessional($this);
    $tenant=Tenant::create(['nome'=>'Outro','slug'=>'other-db']);
    $user=User::create(['tenant_id'=>$tenant->id,'name'=>'Outro','email'=>'other-db@access.test','password'=>'TestPassword123!']);
    expect(fn () => DB::transaction(fn () => DB::table('agenda_professionals')->where('id',$professional->id)->update(['user_id'=>$user->id])))->toThrow(\Illuminate\Database\QueryException::class);
    $second=AgendaProfessional::create(['nome'=>'Segunda pessoa']);
    expect(fn () => DB::transaction(fn () => DB::table('agenda_professionals')->where('id',$second->id)->update(['user_id'=>$professional->user_id])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('only sends recovery emails to eligible users and keeps suspended recovery responses generic', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $this->travel(61)->seconds();
    Notification::fake();auth()->logout();TenantContext::forget();
    Livewire::test(\App\Filament\Pages\Auth\RequestPasswordReset::class)->fillForm(['email'=>$user->email])->call('request')->assertHasNoFormErrors();
    Notification::assertSentTo($user,\App\Notifications\AgendaResetPassword::class);
    $this->actingAs($this->admin);$this->access->manage($this->admin,$professional,'suspend');
    Notification::fake();auth()->logout();TenantContext::forget();
    Livewire::test(\App\Filament\Pages\Auth\RequestPasswordReset::class)->fillForm(['email'=>$user->email])->call('request')->assertHasNoFormErrors();
    Notification::assertNothingSent();
});

it('rejects professional access to team conversations and colleague photos', function () {
    $professional=accessProfessional($this);$user=$this->access->userFor($professional);
    $this->access->completePassword($user,'abcdefgh');$user=$user->fresh();
    $this->actingAs($user)->withSession(['agenda.access_version'=>$user->agenda_access_version]);
    $this->get('/perfil-foto/'.$this->admin->id)->assertForbidden();
    $this->get('/media/1')->assertForbidden();
    $callbacks=\Illuminate\Support\Facades\Broadcast::driver()->getChannels();
    expect($callbacks['tenant.{tenantId}.conversations']($user,$user->tenant_id))->toBeFalse()->and($callbacks['equipe.{tenantId}']($user,$user->tenant_id))->toBeFalse()->and($callbacks['App.Models.User.{id}']($user,$user->id))->toBeTrue();
});
