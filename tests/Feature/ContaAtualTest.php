<?php
use App\Models\{Tenant,User};
use App\Support\TenantContext;
it('permite superadmin selecionar conta e retornar a gestao',function(){
 $t=Tenant::create(['nome'=>'Cliente','slug'=>'cliente']);
 $u=User::create(['name'=>'Operador','email'=>'op@example.test','password'=>'password']);
 $u->forceFill(['operador'=>true,'super_admin'=>true,'perfil_preenchido_em'=>now()])->saveQuietly();
 $this->actingAs($u)->post('/suporte/selecionar',['tenant_id'=>$t->id])
 ->assertRedirect('/admin')->assertSessionHas(TenantContext::CHAVE_SESSAO_SUPORTE,$t->id);
 $this->post('/suporte/sair')->assertRedirect('/gestao/clientes')->assertSessionMissing(TenantContext::CHAVE_SESSAO_SUPORTE);
});
it('impede admin de cliente trocar para outra conta',function(){
 $t=Tenant::create(['nome'=>'Cliente','slug'=>'cliente']);
 $u=User::create(['name'=>'Admin','email'=>'admin@example.test','password'=>'password','perfil'=>'admin','tenant_id'=>$t->id]);
 $u->forceFill(['operador'=>false,'super_admin'=>false,'perfil_preenchido_em'=>now()])->saveQuietly();
 $this->actingAs($u)->post('/suporte/selecionar',['tenant_id'=>$t->id])->assertForbidden();
});
