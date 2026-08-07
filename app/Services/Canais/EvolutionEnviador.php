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

    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null): array
    {
        $r = $this->evolution->sendText(
            $this->instancia($canal),
            $destino,
            $texto,
            // O destino serve de remoteJid: e a mesma conversa, vista do lado de la.
            $citar ? \App\Services\EvolutionService::quoted($citar, $destino) : null,
        );

        return ['external_id' => data_get($r, 'key.id')];
    }

    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array
    {
        $tipo = (string) $arquivo['tipo'];
        $base64 = base64_encode($arquivo['bytes']);
        $quoted = $citar ? \App\Services\EvolutionService::quoted($citar, $destino) : null;

        // Audio vai por endpoint proprio: pelo sendMedia ele chega como arquivo anexado,
        // sem onda e sem play. Isto veio do job e nao mudou de comportamento — mudou de
        // lugar, para o job parar de conhecer a Evolution.
        $r = $tipo === 'audio'
            ? $this->evolution->sendAudio($this->instancia($canal), $destino, $base64, $quoted)
            : $this->evolution->sendMedia(
                $this->instancia($canal),
                $destino,
                $tipo === 'sticker' ? 'image' : $tipo,
                $base64,
                $arquivo['legenda'] ?? null,
                $arquivo['nome'] ?? null,
                $arquivo['mime'] ?? null,
                $quoted,
            );

        return ['external_id' => data_get($r, 'key.id')];
    }

    public function verificarNumero(Channel $canal, string $e164): array
    {
        $resposta = $this->evolution->checkNumbers($this->instancia($canal), [ltrim($e164, '+')]);

        $info = collect($resposta)->first();

        if (! is_array($info)) {
            return ['existe' => null, 'e164' => null];
        }

        return [
            'existe' => (bool) ($info['exists'] ?? false),
            'e164'   => $info['jid'] ?? $info['number'] ?? null,
        ];
    }

    public function marcarLida(Channel $canal, string $jid, array $externalIds): void
    {
        // A Evolution marca uma lista de uma vez, e cada item precisa do jid junto: no
        // Baileys o recibo de leitura e por conversa, nao por id solto.
        $this->evolution->marcarComoLida($this->instancia($canal), array_map(
            fn (string $id) => ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id],
            array_values($externalIds),
        ));
    }

    /**
     * Falta de instancia ESTOURA em vez de virar chamada para uma URL torta.
     *
     * Simetrico ao Phone Number ID no driver da Meta: erro de configuracao tem de
     * aparecer como erro de configuracao, e nao como 404 do provedor.
     */
    private function instancia(Channel $canal): string
    {
        $nome = trim((string) $canal->instance_name);

        if ($nome === '') {
            throw new ConfiguracaoInvalida(
                "O canal \"{$canal->nome}\" e da Evolution mas nao tem instancia."
            );
        }

        return $nome;
    }
}
