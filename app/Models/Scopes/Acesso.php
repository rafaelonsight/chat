<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * O que esta pessoa pode ver.
 *
 * ESCOPO GLOBAL, E NAO FILTRO EM CADA CONSULTA. Restricao de acesso aplicada a mao e restricao
 * que um dia alguem esquece — e o esquecimento aqui nao da erro, da vazamento: o atendente ve a
 * conversa de um canal que nao e dele e ninguem percebe. Do jeito que esta, o padrao e restrito
 * e quem quiser ver tudo precisa dizer isso explicitamente (withoutGlobalScope).
 *
 * O acerto colateral que compensou o desenho: filtro de canal, "nova conversa", relatorios e
 * qualquer consulta futura ficam restritos sem uma linha a mais em cada lugar.
 *
 * AS DUAS REGRAS, e elas nao sao simetricas de proposito:
 *
 *   CANAL sem vinculo  -> ve todos. E o padrao que nao tranca ninguem para fora quando isto
 *                         sobe, porque hoje ninguem tem canal vinculado.
 *   CANAL com vinculo  -> ve so os vinculados.
 *   TIME  sem vinculo  -> ve tudo, inclusive conversa sem time.
 *   TIME  com vinculo  -> ve SO as conversas dos times dele, e NAO ve as sem time. Decisao do
 *                         Rafael: a triagem e feita pelo chatbot ou por quem esta na equipe
 *                         Triagem, entao a fila de entrada nao e de todo mundo.
 *
 * ADMINISTRADOR PASSA POR CIMA. Quem configura o sistema precisa ver o sistema inteiro, e
 * operador do produto tambem.
 */
class Acesso implements Scope
{
    public function __construct(
        private readonly string $colunaDoCanal,
        private readonly ?string $colunaDoTime = null,
    ) {}

    public function apply(Builder $query, Model $model): void
    {
        $usuario = auth()->user();

        // Sem usuario logado nao ha restricao a aplicar: e job, fila, webhook ou console, onde o
        // sistema age em nome de si mesmo. Restringir aqui pararia a entrada de mensagem.
        if (! $usuario || $usuario->veTudo()) {
            return;
        }

        $tabela = $model->getTable();

        if (($canais = $usuario->canalIds()) !== []) {
            $query->whereIn($tabela.'.'.$this->colunaDoCanal, $canais);
        }

        if ($this->colunaDoTime !== null && ($times = $usuario->equipeIds()) !== []) {
            $query->whereIn($tabela.'.'.$this->colunaDoTime, $times);
        }
    }
}
