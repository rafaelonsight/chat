<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra o painel do produto quando a licenca do tenant nao vale.
 *
 * OPERADOR NUNCA E BARRADO: e quem resolve a licenca de um cliente, e travar o proprio
 * acesso dele junto seria a mesma pessoa que teria de destravar ficando de fora.
 *
 * Sem licenca (tenant antigo, criado antes desta tabela existir) conta como valida — o
 * gatilho e a AUSENCIA de acesso, nao a ausencia de registro. Uma migracao futura de dados
 * cobre o passado; este middleware so decide o que ve.
 */
class LicencaValida
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (! $usuario || $usuario->operador) {
            return $next($request);
        }

        if ($request->routeIs('licenca.bloqueada', 'filament.admin.auth.logout')) {
            return $next($request);
        }

        $licenca = $usuario->tenant?->license;

        if (! $licenca || $licenca->estaValida()) {
            return $next($request);
        }

        return redirect()->route('licenca.bloqueada');
    }
}
