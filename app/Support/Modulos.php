<?php

namespace App\Support;

use App\Models\License;

/*
 * Qual modulo a conta tem. E a UNICA pergunta que o resto do codigo faz sobre plano: tela, rota,
 * job e agendador chamam Modulos::ativo('campanhas') e nao sabem o que e "plano Equipe".
 *
 * Registrado como scoped: a resposta vale por requisicao e por job. Um worker do Horizon fica
 * vivo por horas; memoria estatica faria uma licenca alterada so valer no proximo restart.
 */
class Modulos
{
    /** @var array<int, list<string>> */
    private array $memo = [];

    public static function ativo(string $modulo, ?int $tenantId = null): bool
    {
        $tenantId ??= TenantContext::get();

        // Sem conta (console, rotina do sistema, visitante) nao ha plano a conferir. Quem atende
        // uma conta especifica fora do contexto passa o tenant explicitamente.
        if (! $tenantId) {
            return true;
        }

        return in_array($modulo, app(self::class)->daConta($tenantId), true);
    }

    /** @return list<string> */
    public function daConta(int $tenantId): array
    {
        return $this->memo[$tenantId] ??= $this->calcular($tenantId);
    }

    public function esquecer(): void
    {
        $this->memo = [];
    }

    /** @return list<string> */
    private function calcular(int $tenantId): array
    {
        $licenca = License::query()->where('tenant_id', $tenantId)->first(['plano', 'modulos']);

        $planos = config('modulos.planos');
        $plano = $planos[$licenca?->plano] ?? $planos[config('modulos.plano_padrao')];
        $lista = $plano['modulos'];

        // Excecoes da conta. Lista = modulos a mais; mapa = liga (true) ou desliga (false) um a um.
        $excecoes = (array) ($licenca?->modulos ?? []);
        if (array_is_list($excecoes)) {
            $lista = [...$lista, ...$excecoes];
        } else {
            foreach ($excecoes as $modulo => $ligado) {
                $lista = $ligado ? [...$lista, $modulo] : array_diff($lista, [$modulo]);
            }
        }

        return array_values(array_unique(array_intersect($lista, array_keys(config('modulos.catalogo')))));
    }
}
