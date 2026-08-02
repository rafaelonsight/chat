<?php

namespace App\Support;

// Guarda o tenant atual. Em requisicao HTTP ele vem do usuario logado; em job
// de fila nao existe usuario, entao o job seta explicitamente antes de operar.
class TenantContext
{
    private const KEY = 'onchat.tenant_id';

    public static function set(?int $id): void
    {
        app()->instance(self::KEY, $id);
    }

    public static function get(): ?int
    {
        if (app()->bound(self::KEY)) {
            return app(self::KEY);
        }

        return auth()->user()?->tenant_id;
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::KEY);
    }

    // Executa o callback sob outro tenant e devolve o contexto anterior no fim,
    // mesmo se o callback lancar excecao.
    public static function runAs(int $id, callable $fn): mixed
    {
        $tinhaContexto = app()->bound(self::KEY);
        $anterior = $tinhaContexto ? app(self::KEY) : null;

        self::set($id);

        try {
            return $fn();
        } finally {
            $tinhaContexto ? self::set($anterior) : self::forget();
        }
    }
}
