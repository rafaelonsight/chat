<?php

namespace App\Support;

// Guarda o tenant atual. Em requisicao HTTP ele vem do usuario logado; em job
// de fila nao existe usuario, entao o job seta explicitamente antes de operar.
class TenantContext
{
    private const KEY = 'onchat.tenant_id';

    // Trava de reentrancia. Sem ela: o guard resolve o usuario consultando a
    // tabela users, essa consulta dispara o escopo global de tenant, o escopo
    // chama get(), get() chama auth()->user(), o guard ainda nao resolveu e
    // consulta users de novo — recursao infinita ate estourar a memoria.
    private static bool $resolvendoUsuario = false;

    public static function set(?int $id): void
    {
        app()->instance(self::KEY, $id);
    }

    public static function get(): ?int
    {
        if (app()->bound(self::KEY)) {
            return app(self::KEY);
        }

        // Enquanto o proprio usuario autenticado esta sendo carregado, o escopo
        // de tenant fica desligado. E seguro: a busca e por chave primaria vinda
        // da sessao assinada, nao um filtro que poderia vazar outro tenant.
        if (self::$resolvendoUsuario) {
            return null;
        }

        self::$resolvendoUsuario = true;

        try {
            return auth()->user()?->tenant_id;
        } finally {
            self::$resolvendoUsuario = false;
        }
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::KEY);
        self::$resolvendoUsuario = false;
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
