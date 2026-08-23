<?php

namespace App\Services\Canais;

use App\Models\Channel;
use App\Services\Canais\Concerns\EnviaPeloSendApi;

/**
 * Envio pelo Messenger, com o token da Pagina (nao existe token global de reserva:
 * cada Pagina e de um cliente diferente, sem numero "nosso" como no WhatsApp).
 */
class MetaMessengerEnviador implements Enviador
{
    use EnviaPeloSendApi;

    public function __construct(
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null): array
    {
        return $this->enviarMensagem($canal, $this->idDoDestino($destino, $canal), ['text' => $texto]);
    }

    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array
    {
        return $this->enviarAnexo($canal, $this->idDoDestino($destino, $canal), $arquivo);
    }

    public function nome(): string
    {
        return 'messenger';
    }
}
