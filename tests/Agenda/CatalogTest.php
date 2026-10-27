<?php

use App\Filament\Resources\Agenda\Services\Pages\CreateService;
use App\Filament\Resources\Agenda\Services\Pages\EditService;
use App\Filament\Resources\Agenda\Professionals\Pages\CreateProfessional;
use App\Filament\Resources\Agenda\Professionals\Pages\EditProfessional;
use App\Models\{AgendaService,AgendaProfessional,Tenant,User};
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function catalogActor(string $suffix, bool $admin=true): User {
    $tenant=Tenant::create(['nome'=>'Estabelecimento '.$suffix,'slug'=>'catalog-'.$suffix]);
    return User::create(['tenant_id'=>$tenant->id,'name'=>'Actor '.$suffix,'email'=>$suffix.'@catalog.test','password'=>'OnlyForTests123!','admin'=>$admin,'perfil'=>$admin?'admin':'atendente']);
}
function catalogService(array $overrides=[]): AgendaService {
    return AgendaService::create(array_merge(['nome'=>'Serviço de teste','preco'=>'49.90','duracao_minutos'=>30,'intervalo_minutos'=>10],$overrides));
}

beforeEach(function () {
    TenantContext::forget();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actor=catalogActor('a');
    $this->actingAs($this->actor);
});
afterEach(fn () => TenantContext::forget());

it('creates and edits services through the actual panel form', function () {
    Livewire::test(CreateService::class)->fillForm([
        'nome'=>'Corte personalizado','descricao'=>'Acabamento à escolha do cliente.',
        'preco'=>'49.90','duracao_minutos'=>40,'intervalo_minutos'=>10,'ativo'=>true,
    ])->call('create')->assertHasNoFormErrors();
    $service=AgendaService::firstOrFail();
    expect($service->tenant_id)->toBe($this->actor->tenant_id)->and($service->preco)->toBe('49.90');
    Livewire::test(EditService::class,['record'=>$service->id])->fillForm(['nome'=>'Corte e finalização','ativo'=>false])->call('save')->assertHasNoFormErrors();
    expect($service->fresh()->nome)->toBe('Corte e finalização')->and($service->fresh()->ativo)->toBeFalse();
});

it('validates service values on the form and domain', function () {
    Livewire::test(CreateService::class)->fillForm(['nome'=>'Inválido','preco'=>'-1','duracao_minutos'=>0,'intervalo_minutos'=>-1,'ativo'=>true])
        ->call('create')->assertHasFormErrors(['preco','duracao_minutos','intervalo_minutos']);
    expect(fn () => catalogService(['duracao_minutos'=>0]))->toThrow(ValidationException::class);
    expect(fn () => catalogService(['intervalo_minutos'=>-1]))->toThrow(ValidationException::class);
    expect(fn () => catalogService(['preco'=>'1.001']))->toThrow(ValidationException::class);
    expect(fn () => catalogService(['nome'=>'   ']))->toThrow(ValidationException::class);
    expect(catalogService(['preco'=>'0.10','intervalo_minutos'=>0])->preco)->toBe('0.10');
});

it('creates and edits professionals with persisted service assignments', function () {
    $service=catalogService();
    Livewire::test(CreateProfessional::class)->fillForm([
        'nome'=>'Ana','descricao'=>'Especialista em atendimento personalizado.',
        'email'=>'ANA@example.test','telefone'=>'11999990000','cor'=>'#6338F6','ativo'=>true,'services'=>[$service->id],
    ])->call('create')->assertHasNoFormErrors();
    $professional=AgendaProfessional::with('services')->firstOrFail();
    expect($professional->services->modelKeys())->toBe([$service->id])->and($professional->email)->toBe('ana@example.test');
    Livewire::test(EditProfessional::class,['record'=>$professional->id])->fillForm(['services'=>[],'ativo'=>false])->call('save')->assertHasNoFormErrors();
    expect($professional->fresh()->services)->toHaveCount(0)->and($professional->fresh()->ativo)->toBeFalse();
});

it('rejects malformed professional details', function () {
    expect(fn () => AgendaProfessional::create(['nome'=>'Ana','cor'=>'red; background:url(test)']))->toThrow(ValidationException::class);
    expect(fn () => AgendaProfessional::create(['nome'=>'Ana','email'=>'invalid']))->toThrow(ValidationException::class);
});

it('scopes catalogs and blocks direct cross-account record URLs', function () {
    $own=catalogService(['nome'=>'Serviço próprio']);
    $otherActor=catalogActor('b');
    $other=TenantContext::runAs($otherActor->tenant_id, fn () => catalogService(['nome'=>'Serviço de outra conta']));
    expect(AgendaService::pluck('id')->all())->toBe([$own->id]);
    $this->get('/admin/servicos/'.$other->id.'/editar')->assertNotFound();
    $this->get('/admin/servicos')->assertOk()->assertSee('Serviço próprio')->assertDontSee('Serviço de outra conta');
});

it('fails closed without a tenant and refuses tenant changes', function () {
    $service=catalogService();
    $other=catalogActor('b');
    expect(fn () => $service->forceFill(['tenant_id'=>$other->tenant_id])->save())->toThrow(ValidationException::class);
    auth()->logout();TenantContext::forget();
    expect(AgendaService::count())->toBe(0)->and(AgendaProfessional::count())->toBe(0);
    expect(fn () => catalogService())->toThrow(ValidationException::class);
});

it('rejects cross-account service assignment without losing existing services', function () {
    $own=catalogService();
    $professional=AgendaProfessional::create(['nome'=>'Ana']);
    $professional->syncServices([$own->id]);
    $otherActor=catalogActor('b');
    $other=TenantContext::runAs($otherActor->tenant_id, fn () => catalogService());
    expect(fn () => $professional->syncServices([$other->id]))->toThrow(ValidationException::class);
    expect($professional->fresh()->services->modelKeys())->toBe([$own->id]);
    $this->assertDatabaseMissing('agenda_professional_service',['professional_id'=>$professional->id,'service_id'=>$other->id]);
});

it('enforces cross-account relation integrity at the database boundary', function () {
    $professional=AgendaProfessional::create(['nome'=>'Ana']);
    $otherActor=catalogActor('b');
    $other=TenantContext::runAs($otherActor->tenant_id, fn () => catalogService());
    expect(fn () => DB::transaction(fn () => DB::table('agenda_professional_service')->insert([
        'tenant_id'=>$this->actor->tenant_id,'professional_id'=>$professional->id,'service_id'=>$other->id,
    ])))->toThrow(QueryException::class);
});

it('rejects tampered professional form assignments', function () {
    $own=catalogService();
    $professional=AgendaProfessional::create(['nome'=>'Ana']);
    $professional->syncServices([$own->id]);
    $otherActor=catalogActor('b');
    $other=TenantContext::runAs($otherActor->tenant_id, fn () => catalogService());
    Livewire::test(EditProfessional::class,['record'=>$professional->id])
        ->fillForm(['services'=>[$other->id]])->call('save')->assertHasFormErrors(['services']);
    expect($professional->fresh()->services->modelKeys())->toBe([$own->id]);
    Livewire::test(CreateProfessional::class)
        ->fillForm(['nome'=>'Cadastro adulterado','cor'=>'#6338F6','ativo'=>true,'services'=>[$other->id]])
        ->call('create')->assertHasFormErrors(['services']);
    $this->assertDatabaseMissing('agenda_professionals',['nome'=>'Cadastro adulterado']);
});

it('enforces duration and price constraints even on raw SQL writes', function () {
    $service=catalogService();
    expect(fn () => DB::transaction(fn () => DB::table('agenda_services')->where('id',$service->id)->update(['duracao_minutos'=>0])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('agenda_services')->where('id',$service->id)->update(['preco'=>-1])))->toThrow(QueryException::class);
});

it('allows reception to consult but not manage or delete the catalog', function () {
    $service=catalogService();
    $professional=AgendaProfessional::create(['nome'=>'Ana']);
    $professional->syncServices([$service->id]);
    User::create(['tenant_id'=>$this->actor->tenant_id,'name'=>'Outro administrador','email'=>'other-admin@catalog.test','password'=>'OnlyForTests123!','perfil'=>'admin']);
    $this->actor->forceFill(['admin'=>false,'perfil'=>'atendente'])->save();
    $this->get('/admin/servicos')->assertOk();
    $this->get('/admin/profissionais')->assertOk()->assertSee('Ana')->assertSee('Serviço de teste');
    $this->get('/admin/servicos/novo')->assertForbidden();
    $this->get('/admin/profissionais/novo')->assertForbidden();
    $this->get('/admin/servicos/'.$service->id.'/editar')->assertForbidden();
    expect(Gate::allows('delete',$service))->toBeFalse()->and(Gate::allows('update',$professional))->toBeFalse();
});

it('keeps assignments when services are deactivated and personalises the home', function () {
    $service=catalogService();
    $professional=AgendaProfessional::create(['nome'=>'Ana']);
    $professional->syncServices([$service->id]);
    $service->update(['ativo'=>false]);
    expect($professional->fresh()->services)->toHaveCount(1);
    $this->get('/admin')->assertOk()->assertSee('Estabelecimento a')->assertSee('Seu atendimento, do seu jeito.');
});
