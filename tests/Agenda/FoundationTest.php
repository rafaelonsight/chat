<?php

use App\Models\Tenant;
use App\Models\User;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Notifications\AgendaResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    App\Support\TenantContext::forget();
});
afterEach(fn () => App\Support\TenantContext::forget());

function agendaActor(): User {
    $tenant=Tenant::create(['nome'=>'Test salon','slug'=>'test-salon']);
    return User::create(['tenant_id'=>$tenant->id,'name'=>'Test Admin','email'=>'admin@example.test','password'=>'OnlyForTests123!','admin'=>true,'perfil'=>'admin']);
}

it('signs in with email and password', function () {
    $user=agendaActor();
    Livewire::test(Login::class)->fillForm(['email'=>'ADMIN@example.test','password'=>'OnlyForTests123!'])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($user);
});
it('rejects wrong passwords', function () {
    agendaActor();
    Livewire::test(Login::class)->fillForm(['email'=>'admin@example.test','password'=>'Incorrect123!'])->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
});
it('renders a branded Portuguese password recovery email', function () {
    $user=agendaActor();
    $notification=new AgendaResetPassword('test-token');
    $notification->url='https://agenda.example.test/reset?token=test-token';
    $mail=$notification->toMail($user);
    expect($mail->subject)->toBe('Redefinir sua senha · Virtus Agenda');
    $html=view($mail->view,$mail->viewData)->render();
    expect($html)->toContain('Redefinir senha','8 caracteres','60 minutos','#6338f6','pt-BR')->not->toContain('Hello!','Regards,');
});
it('explains the minimum password length in Portuguese', function () {
    app()->setLocale('pt_BR');
    $validator=Illuminate\Support\Facades\Validator::make(
        ['password'=>'short'],
        ['password'=>[Illuminate\Validation\Rules\Password::defaults()]],
    );
    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('password'))->toBe('O campo senha deve ter pelo menos 8 caracteres.');
    expect(Illuminate\Support\Facades\Validator::make(
        ['password'=>'abcdefgh'],
        ['password'=>[Illuminate\Validation\Rules\Password::defaults()]],
    )->passes())->toBeTrue();
});
it('serves login without exposing legacy modules', function () {
    $this->get('/admin/login')->assertOk()->assertSee('password');
    foreach(['/gestao','/admin/agenda','/admin/campaigns','/acesso/codigo'] as $path) $this->get($path)->assertNotFound();
    $this->get('/webhooks/meta/whatsapp')->assertStatus(503);
});
it('requires a tenant even with a valid authenticated session', function () {
    $user=User::create(['name'=>'Unbound','email'=>'unbound@example.test','password'=>'OnlyForTests123!']);
    $this->actingAs($user)->get('/admin')->assertForbidden();
});
it('logs out when the session is locked', function () {
    $user=agendaActor();
    $this->actingAs($user)->post('/sessao/bloquear')->assertRedirect('/admin/login');
    $this->assertGuest();
});
it('does not create a trial license', function () {
    agendaActor();
    $this->assertDatabaseCount('licenses',0);
});
it('sends a password recovery notification and consumes its token once', function () {
    Notification::fake();
    $user=agendaActor();
    Livewire::test(RequestPasswordReset::class)->fillForm(['email'=>$user->email])->call('request')->assertHasNoFormErrors();
    Notification::assertSentTo($user,AgendaResetPassword::class);
    $token=Password::broker()->createToken($user);
    $credentials=['email'=>$user->email,'token'=>$token,'password'=>'Replacement123!','password_confirmation'=>'Replacement123!'];
    $callback=fn ($actor,$password) => $actor->forceFill(['password'=>Hash::make($password)])->save();
    expect(Password::broker()->reset($credentials,$callback))->toBe(Password::PASSWORD_RESET);
    expect(Password::broker()->reset($credentials,$callback))->toBe(Password::INVALID_TOKEN);
});
