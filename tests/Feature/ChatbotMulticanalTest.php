<?php
use App\Models\{Channel, Chatbot, Tenant, User};
use App\Services\ChatbotFluxo;
use App\Support\TenantContext;
use App\Filament\Resources\Chatbots\Pages\{CreateChatbot, ListChatbots};
use Filament\Facades\Filament;
use Livewire\Livewire;
beforeEach(function () {
 $this->tenant=Tenant::create(['nome'=>'Multi','slug'=>'multi']);
 TenantContext::set($this->tenant->id);
 $this->a=Channel::create(['nome'=>'A']);
 $this->b=Channel::create(['nome'=>'B']);
 $this->c=Channel::create(['nome'=>'C']);
});
afterEach(fn()=>TenantContext::forget());
function botMulti(array $ids): Chatbot {
 return Chatbot::create(CreateChatbot::defaults(['nome'=>'Multi', 'channel_ids'=>$ids]));
}
it('publica um fluxo para dois canais sem atender um terceiro', function () {
 $bot=botMulti([$this->a->id,$this->b->id]);
 $fluxo=app(ChatbotFluxo::class); $fluxo->criarExemplo($bot);
 expect($fluxo->publicar($bot))->toBe([])
 ->and(Chatbot::publicadoPara($this->a)?->id)->toBe($bot->id)
 ->and(Chatbot::publicadoPara($this->b)?->id)->toBe($bot->id)
 ->and(Chatbot::publicadoPara($this->c))->toBeNull();
});
it('bloqueia conflito sem desativar ou substituir o fluxo existente', function () {
 $fluxo=app(ChatbotFluxo::class);
 $um=botMulti([$this->a->id,$this->b->id]); $fluxo->criarExemplo($um); $fluxo->publicar($um);
 $dois=botMulti([$this->b->id,$this->c->id]); $fluxo->criarExemplo($dois);
 expect($fluxo->publicar($dois))->not->toBe([])
 ->and($um->fresh()->ativo)->toBeTrue()
 ->and($dois->fresh()->ativo)->toBeFalse()
 ->and(Chatbot::publicadoPara($this->c))->toBeNull();
});
it('recusa canal de outra conta', function () {
 $outro=Tenant::create(['nome'=>'Outra','slug'=>'outra']);
 $canal=Channel::create(['tenant_id'=>$outro->id,'nome'=>'Externo']);
 expect(fn()=>botMulti([$canal->id]))->toThrow(\Illuminate\Validation\ValidationException::class);
});
it('cria pelo modal e redireciona ao canvas como rascunho', function () {
 $u=User::create(['name'=>'Admin','email'=>'multi@example.test','password'=>'password','perfil'=>'admin']);
 $u->forceFill(['perfil_preenchido_em'=>now()])->saveQuietly(); $this->actingAs($u);
 Filament::setCurrentPanel(Filament::getPanel('admin'));
 Livewire::test(ListChatbots::class)->callAction('criarChatbot', data:['nome'=>'Novo','channel_ids'=>[$this->a->id,$this->b->id]])->assertHasNoActionErrors();
 $bot=Chatbot::where('nome','Novo')->firstOrFail();
 expect($bot->ativo)->toBeFalse()->and($bot->status)->toBe('rascunho')->and($bot->channel_ids)->toBe([$this->a->id,$this->b->id]);
});

it('cria modelos independentes e exige configurar equipes antes de publicar', function (string $modelo) {
 $servico=app(\App\Services\ChatbotModelos::class);
 $a=$servico->criar(['modelo'=>$modelo,'nome'=>'Modelo A','channel_ids'=>[$this->a->id]]);
 $b=$servico->criar(['modelo'=>$modelo,'nome'=>'Modelo B','channel_ids'=>[$this->b->id]]);
 expect($a->ativo)->toBeFalse()->and($a->status)->toBe('rascunho')
 ->and($a->actions()->count())->toBeGreaterThan(0)
 ->and($a->steps()->pluck('id')->intersect($b->steps()->pluck('id'))->count())->toBe(0);
 $f=app(ChatbotFluxo::class);
 expect($f->validar($a))->not->toBe([]);
 $equipe=\App\Models\Team::create(['nome'=>'Equipe']);
 foreach($a->actions()->where('tipo','transferir')->get() as $acao) {
  $cfg=$acao->config; $cfg['team_id']=$equipe->id; $acao->update(['config'=>$cfg]);
 }
 expect($f->validar($a))->toBe([]);
})->with(['recepcao','suporte','financeiro','comercial']);
