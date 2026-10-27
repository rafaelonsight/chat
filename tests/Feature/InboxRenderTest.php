<?php

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;

it('renderiza o atendimento com o usuario carregado da sessao', function () {
    $t = Tenant::create(['nome' => 'T', 'slug' => 'ibx']);
    TenantContext::set($t->id);
    $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'email' => 'ibx@t.test', 'password' => 'segredo123']);
    $u->forceFill(['name'=>'Usuário Silva','apelido'=>'Usuário','telefone'=>'11999999999','perfil_preenchido_em'=>now()])->saveQuietly();
    TenantContext::forget();

    $chave = 'login_web_'.sha1('Illuminate\Auth\SessionGuard');

    $this->withoutExceptionHandling();
    $this->withSession([$chave => $u->id])
        ->get('/admin')
        ->assertSuccessful()
        ->assertSee('Novos');
});
