<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Faz o operador global trabalhar dentro da conta escolhida sem alterar o tenant
 * de origem do usuario. A selecao so existe na sessao do proprio superadmin.
 */
class ContextoDeSuporte
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::forget();

        $usuario = $request->user();
        $tenantId = $request->session()->get(TenantContext::CHAVE_SESSAO_SUPORTE);

        if ($usuario?->operador && $usuario->super_admin && $tenantId
            && Tenant::whereKey($tenantId)->exists()) {
            TenantContext::set((int) $tenantId);
        } elseif ($tenantId) {
            // Sessao antiga, tenant removido ou privilegio revogado nao pode deixar
            // um contexto pendurado que um proximo login herdaria.
            $request->session()->forget(TenantContext::CHAVE_SESSAO_SUPORTE);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }
}
