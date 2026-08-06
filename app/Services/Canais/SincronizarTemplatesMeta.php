<?php

namespace App\Services\Canais;

use App\Models\Channel;
use App\Models\MetaTemplate;
use App\Support\TenantContext;

/**
 * Traz os templates da Meta para o banco.
 *
 * Espelho local de proposito: a tela do atendente nao pode depender de uma chamada a API
 * da Meta para listar o que ele pode enviar — seria lento no melhor caso e tela vazia no
 * pior. A fonte da verdade continua sendo a Meta; isto e copia com hora de sincronizacao.
 */
class SincronizarTemplatesMeta
{
    public function __construct(private readonly MetaCloudEnviador $meta) {}

    /**
     * @return array{ok: bool, erro?: string, total?: int, novos?: int, nao_suportados?: int, apagados?: int}
     */
    public function paraCanal(Channel $canal): array
    {
        $r = $this->meta->templates($canal);

        if (! $r['ok']) {
            return ['ok' => false, 'erro' => $r['erro'] ?? 'falha ao listar templates'];
        }

        // runAs porque isto tambem vai rodar em job, e job nao tem usuario logado: sem
        // contexto de tenant o escopo global nao acha nem grava nada.
        return TenantContext::runAs($canal->tenant_id, function () use ($canal, $r) {
            $novos = 0;
            $naoSuportados = 0;
            $vistos = [];

            foreach ($r['templates'] as $bruto) {
                $nome = (string) ($bruto['name'] ?? '');
                $idioma = (string) ($bruto['language'] ?? '');

                if ($nome === '' || $idioma === '') {
                    continue;
                }

                $modelo = MetaTemplate::updateOrCreate(
                    [
                        'meta_waba_id' => (string) $canal->meta_waba_id,
                        'nome'         => $nome,
                        'idioma'       => $idioma,
                    ],
                    array_merge(MetaTemplate::analisar($bruto), [
                        'tenant_id'       => $canal->tenant_id,
                        'meta_id'         => (string) ($bruto['id'] ?? ''),
                        'categoria'       => $bruto['category'] ?? null,
                        'status'          => (string) ($bruto['status'] ?? ''),
                        'componentes'     => $bruto['components'] ?? [],
                        'sincronizado_em' => now(),
                    ]),
                );

                $novos += $modelo->wasRecentlyCreated ? 1 : 0;
                $naoSuportados += $modelo->suportado ? 0 : 1;
                $vistos[] = $modelo->id;
            }

            // Template apagado na Meta NAO pode continuar oferecido: o atendente
            // escolheria e o envio falharia com "template does not exist" — erro que
            // parece nosso e nao e. Some daqui tambem.
            $apagados = MetaTemplate::where('meta_waba_id', (string) $canal->meta_waba_id)
                ->whereNotIn('id', $vistos ?: [0])
                ->delete();

            return [
                'ok'             => true,
                'total'          => count($vistos),
                'novos'          => $novos,
                'nao_suportados' => $naoSuportados,
                'apagados'       => $apagados,
            ];
        });
    }
}
