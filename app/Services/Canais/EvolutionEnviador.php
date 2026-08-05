<?php

namespace App\Services\Canais;

use App\Models\Channel;
use App\Services\EvolutionService;

/**
 * Envio pelo Baileys, via Evolution API.
 *
 * Embrulha o servico que ja existia, sem alterar o comportamento: o que muda e quem
 * decide chamar. Antes o job de envio conhecia a Evolution; agora conhece a interface.
 */
class EvolutionEnviador implements Enviador
{
    public function __construct(private readonly EvolutionService $evolution) {}

    public function nome(): string
    {
        return 'evolution';
    }

    public function texto(Channel $canal, string $destino, string $texto): array
    {
        $r = $this->evolution->sendText($canal->instance_name, $destino, $texto);

        return ['external_id' => data_get($r, 'key.id')];
    }
}
