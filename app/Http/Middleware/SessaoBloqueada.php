<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Segura o painel quando a pessoa bloqueou a propria sessao.
 *
 * Bloquear e diferente de sair: o atendente levanta do balcao por cinco minutos e
 * volta com a senha, sem perder o que estava escrevendo nem a conversa aberta.
 * Sair, num posto de atendimento compartilhado, faz a pessoa nao bloquear nada.
 *
 * A trava vive na SESSAO, nao num campo do usuario: e por maquina. Bloquear no
 * balcao nao pode derrubar o mesmo usuario no celular.
 */
class SessaoBloqueada
{
    public const CHAVE = 'onchat.sessao.bloqueada';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get(self::CHAVE)) {
            return $next($request);
        }

        // A propria tela de bloqueio, o destravar e a SAIDA continuam passando —
        // senao a pessoa fica presa sem nem conseguir sair.
        if ($request->routeIs('sessao.travada', 'sessao.destravar', 'filament.admin.auth.logout')) {
            return $next($request);
        }

        return redirect()->route('sessao.travada');
    }
}
