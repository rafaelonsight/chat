<?php
use App\Models\{User,Tenant,Channel,Contact,Conversation,Message};
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
beforeEach(function(){
 $this->usuario=User::factory()->create(['name'=>'Rafael Paulino da Silva','perfil_preenchido_em'=>null]);$this->actingAs($this->usuario);
});
it('exige perfil inicial antes de entrar no painel e permite abrir o wizard',function(){
 $this->get('/admin')->assertRedirect('/meu-perfil');$this->get('/meu-perfil')->assertOk()->assertSee('Dados pessoais')->assertSee('No atendimento')->assertSee('Conferência');
});
it('salva perfil e libera próximos acessos sem alterar permissões ou email',function(){
 $this->post('/meu-perfil',['name'=>'Rafael Paulino da Silva','telefone'=>'+55 11 99999-9999','apelido'=>'Rafael','cargo'=>'Supervisor','cor_chat'=>'turquesa','admin'=>true,'email'=>'outra@example.test'])->assertRedirect('/admin');
 $u=$this->usuario->refresh();expect($u->perfil_preenchido_em)->not->toBeNull()->and($u->nomePublico())->toBe('Rafael')->and($u->admin)->toBeFalse()->and($u->email)->not->toBe('outra@example.test')->and($u->cor_chat)->toBe('turquesa');
 $this->get('/meu-perfil')->assertSee('Atualize sua apresentação');
});
it('recusa nome incompleto telefone inválido e apelido com marcação',function(){
 $this->post('/meu-perfil',['name'=>'Rafael','telefone'=>'123','apelido'=>'<script>'])->assertSessionHasErrors(['name','apelido']);
 $this->post('/meu-perfil',['name'=>'Rafael Silva','telefone'=>'123','apelido'=>'Rafael'])->assertSessionHasErrors('telefone');
 expect($this->usuario->refresh()->perfil_preenchido_em)->toBeNull();
});
it('guarda foto em disco privado e impede outra empresa de acessar',function(){
 Storage::fake('local');$foto=UploadedFile::fake()->image('perfil.jpg');
 $this->post('/meu-perfil',['name'=>'Rafael Silva','telefone'=>'11999999999','apelido'=>'Rafael','foto'=>$foto])->assertRedirect('/admin');
 $u=$this->usuario->refresh();Storage::disk('local')->assertExists($u->foto_perfil);$this->get('/perfil-foto/'.$u->id)->assertOk();
 $outro=User::factory()->create();$this->actingAs($outro)->get('/perfil-foto/'.$u->id)->assertForbidden();
});
it('recusa arquivo que não é imagem',function(){
 $this->post('/meu-perfil',['name'=>'Rafael Silva','telefone'=>'11999999999','apelido'=>'Rafael','foto'=>UploadedFile::fake()->create('foto.html',10,'text/html')])->assertSessionHasErrors('foto');
});
it('paleta tem vinte cores visuais e valida uma cor desconhecida',function(){
 expect(User::PALETA_CHAT)->toHaveCount(20)->and(array_unique(User::PALETA_CHAT))->toHaveCount(20);
 $this->post('/meu-perfil',['name'=>'Rafael Silva','telefone'=>'11999999999','apelido'=>'Rafael','cor_chat'=>'inventada'])->assertSessionHasErrors('cor_chat');
 $this->usuario->forceFill(['perfil_preenchido_em'=>now(),'admin'=>true,'super_admin'=>false])->saveQuietly();\Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
 Livewire::actingAs($this->usuario)->test(\App\Filament\Resources\Users\Pages\EditUser::class,['record'=>$this->usuario->id])->assertSee('Escolha uma das vinte cores')->assertDontSee('<option value="amarelo"',false);
});
it('assinatura registrada não muda quando o apelido é atualizado',function(){
 $tenant=Tenant::create(['nome'=>'Empresa','slug'=>'assinatura-inicial']);TenantContext::set($tenant->id);
 $this->usuario->forceFill(['tenant_id'=>$tenant->id,'apelido'=>'Rafael'])->saveQuietly();
 $canal=Channel::create(['nome'=>'Canal']);$contato=Contact::create(['nome'=>'Cliente','jid'=>'5511999999999@s.whatsapp.net']);$c=Conversation::create(['channel_id'=>$canal->id,'contact_id'=>$contato->id]);
 $m=Message::create(['conversation_id'=>$c->id,'channel_id'=>$canal->id,'user_id'=>$this->usuario->id,'direcao'=>'out','tipo'=>'text','corpo'=>'Olá']);
 $this->usuario->update(['apelido'=>'Rafa']);expect($m->refresh()->assinatura_nome)->toBe('Rafael')->and($this->usuario->nomePublico())->toBe('Rafa');TenantContext::forget();
});
afterEach(fn()=>TenantContext::forget());
