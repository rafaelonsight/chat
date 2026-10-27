<?php

namespace App\Http\Controllers;

use App\Services\CodigoDeAcesso;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AcessoPorCodigoController
{
    public function mostrar(Request $pedido)
    {
        $painel = Filament::getCurrentPanel()?->getId() ?? 'admin';
        if (Auth::check()) return redirect($painel === 'revenda' ? '/gestao' : '/admin');
        $expiraEm = \Illuminate\Support\Facades\DB::table('codigos_de_acesso')
            ->where('id', $pedido->session()->get('acesso.codigo_id'))->value('expira_em');
        $segundosRestantes = $expiraEm
            ? max(0, (int) ceil(now()->diffInSeconds(\Carbon\Carbon::parse($expiraEm), false)))
            : 0;
        return view('auth.codigo', ['painel' => $painel, 'segundosRestantes' => $segundosRestantes]);
    }

    public function solicitar(Request $pedido, CodigoDeAcesso $acesso)
    {
        $dados = $pedido->validate(['email' => 'required|email|max:254', 'painel' => 'required|in:admin,revenda']);
        $anterior = $pedido->session()->get('acesso.codigo_id');
        $id = $acesso->solicitar($dados['email'], $dados['painel'], $pedido->ip(), $pedido->session()->getId());
        if ($anterior) \Illuminate\Support\Facades\DB::table('codigos_de_acesso')->where('id', $anterior)->update(['usado_em' => now()]);
        $pedido->session()->put(['acesso.codigo_id' => $id, 'acesso.email' => $dados['email'], 'acesso.painel' => $dados['painel'], 'acesso.solicitado' => true]);
        return redirect($dados['painel'] === 'revenda' ? '/gestao/login' : '/admin/login')
            ->with('status', 'Se este e-mail tem acesso, você receberá um token válido por dez minutos.');
    }

    public function trocarEmail(Request $pedido)
    {
        $dados = $pedido->validate(['painel' => 'required|in:admin,revenda']);
        $id = $pedido->session()->get('acesso.codigo_id');
        if ($id) \Illuminate\Support\Facades\DB::table('codigos_de_acesso')->where('id', $id)->update(['usado_em' => now()]);
        $pedido->session()->forget(['acesso.codigo_id', 'acesso.email', 'acesso.painel', 'acesso.solicitado']);
        return redirect($dados['painel'] === 'revenda' ? '/gestao/login' : '/admin/login');
    }

    public function confirmar(Request $pedido, CodigoDeAcesso $acesso)
    {
        $dados = $pedido->validate(['codigo' => 'required|digits:6']);
        $usuario = $acesso->consumir($pedido->session()->get('acesso.codigo_id'), $dados['codigo'], $pedido->session()->getId());
        if (! $usuario) throw ValidationException::withMessages(['codigo' => 'Token inválido, expirado ou sem tentativas disponíveis. Solicite outro token.']);
        $painel = $pedido->session()->get('acesso.painel', 'admin');
        Auth::login($usuario, false);
        $pedido->session()->put('perfil.destino',$painel === 'revenda' ? '/gestao' : '/admin');
        if (! $usuario->email_verified_at) $usuario->forceFill(['email_verified_at' => now()])->save();
        $pedido->session()->forget(['acesso.codigo_id', 'acesso.email', 'acesso.painel', 'acesso.solicitado', \App\Http\Middleware\SessaoBloqueada::CHAVE]);
        $pedido->session()->regenerate();
        if (! $usuario->perfil_preenchido_em) return redirect()->route('perfil.inicial');
        return redirect($painel === 'revenda' ? '/gestao' : '/admin');
    }
}
