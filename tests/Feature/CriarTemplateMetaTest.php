<?php
use App\Models\{Tenant, User, Channel, MetaTemplate};
use App\Services\Canais\CriarTemplateMeta;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use App\Filament\Resources\MetaTemplates\Pages\CreateMetaTemplate;

beforeEach(function() {
    config(['services.meta.token'=>'EAA-env','services.meta.versao'=>'v23.0']);
    $this->tenant=Tenant::create(['nome'=>'Teste','slug'=>'criar-tpl','idioma_comunicacoes'=>'en_GB']);
    TenantContext::set($this->tenant->id);
    $this->user=User::factory()->create(['tenant_id'=>$this->tenant->id,'admin'=>true,'perfil'=>'admin','perfil_preenchido_em'=>now()]);
    $this->actingAs($this->user);
    $this->canal=Channel::create(['nome'=>'Oficial','tipo'=>Channel::META_CLOUD,'meta_phone_number_id'=>'111','meta_waba_id'=>'362'])->refresh();
    $this->dados=['channel_id'=>$this->canal->id,'nome'=>'la_greeting','idioma'=>'en_GB','categoria'=>'MARKETING','corpo'=>'Hello, how are you? We are from LA Group. Would you be happy to have a chat with us?'];
    $this->statusMeta=200; $this->respostaMeta=['id'=>'900','status'=>'PENDING','category'=>'MARKETING'];
    Http::fake(['*'=>fn($req)=>Http::response($req->method()==='GET'?['data'=>[]]:$this->respostaMeta,$req->method()==='GET'?200:$this->statusMeta)]);
});
afterEach(fn()=>TenantContext::forget());

it('salva rascunho e não chama a Meta',function(){
    $dados=app(CriarTemplateMeta::class)->preparar($this->dados);
    $m=MetaTemplate::create($dados);
    expect($m->status)->toBe('DRAFT')->and($m->podeEnviar())->toBeFalse();
    Http::assertNothingSent();
});
it('monta exemplos nomeados e botões',function(){
    $this->dados['corpo']='Hello {{nome}}';$this->dados['exemplos']='Alice';
    $this->dados['botoes']=[['tipo'=>'URL','texto'=>'Website','valor'=>'https://example.com']];
    $d=app(CriarTemplateMeta::class)->preparar($this->dados);
    expect($d['componentes'][0]['example'])->toBe(['body_text_named_params'=>[['param_name'=>'nome','example'=>'Alice']]])
        ->and($d['variaveis'])->toBe(1)->and($d['componentes'][1]['buttons'][0]['url'])->toBe('https://example.com');
});
it('recusa variável sem exemplo',function(){
    $this->dados['corpo']='Hello {{1}}';
    expect(fn()=>app(CriarTemplateMeta::class)->preparar($this->dados))->toThrow(ValidationException::class);
});
it('recusa canal de outra conta',function(){
    $t=Tenant::create(['nome'=>'Outra','slug'=>'outra-tpl']);
    $c=Channel::withoutGlobalScopes()->create(['tenant_id'=>$t->id,'nome'=>'Outro','tipo'=>Channel::META_CLOUD,'meta_waba_id'=>'987']);
    $this->dados['channel_id']=$c->id;
    expect(fn()=>app(CriarTemplateMeta::class)->preparar($this->dados))->toThrow(ValidationException::class);
});
it('solicita aprovação e grava apenas estado confirmado',function(){
    $m=MetaTemplate::create(app(CriarTemplateMeta::class)->preparar($this->dados));
    $r=app(CriarTemplateMeta::class)->solicitar($m);
    expect($r['ok'])->toBeTrue()->and($m->refresh()->status)->toBe('PENDING')->and($m->podeEnviar())->toBeFalse();
    Http::assertSent(fn($req)=>$req->method()==='POST' && str_ends_with($req->url(),'/362/message_templates') && $req['language']==='en_GB' && $req['category']==='MARKETING');
    app(CriarTemplateMeta::class)->solicitar($m);
    expect(collect(Http::recorded())->filter(fn($par)=>$par[0]->method()==='POST')->count())->toBe(1);
});
it('mantém rascunho quando a Meta recusa',function(){
    $this->statusMeta=400;$this->respostaMeta=['error'=>['error_user_msg'=>'Invalid template']];
    $m=MetaTemplate::create(app(CriarTemplateMeta::class)->preparar($this->dados));
    $r=app(CriarTemplateMeta::class)->solicitar($m);
    expect($r['ok'])->toBeFalse()->and($r['erro'])->toBe('Invalid template')->and($m->refresh()->status)->toBe('DRAFT');
});
it('monta autenticação usando texto padronizado pela Meta',function(){
    $this->dados['categoria']='AUTHENTICATION';
    $d=app(CriarTemplateMeta::class)->preparar($this->dados);
    expect($d['componentes'][0])->toBe(['type'=>'BODY','add_security_recommendation'=>true])
        ->and($d['componentes'][2]['buttons'][0]['otp_type'])->toBe('COPY_CODE');
});
it('renderiza wizard e cria um rascunho pelo painel',function(){
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    Livewire::test(CreateMetaTemplate::class)->fillForm($this->dados)->call('create')->assertHasNoFormErrors();
    expect(MetaTemplate::where('nome','la_greeting')->value('status'))->toBe('DRAFT');
    Http::assertNothingSent();
});
