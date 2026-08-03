<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

// Unico ponto do sistema que fala HTTP com a Evolution. Quando a Cloud API
// entrar, e daqui que a interface de driver vai ser extraida — com os dois
// lados conhecidos, em vez de projetada no escuro agora.
class EvolutionService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['apikey' => $this->apiKey])
            ->acceptJson()
            ->timeout(20);
    }

    public function createInstance(string $instance, string $webhookUrl): array
    {
        return $this->client()->post('/instance/create', [
            'instanceName' => $instance,
            'integration'  => 'WHATSAPP-BAILEYS',
            'qrcode'       => true,
            'webhook'      => [
                'url'      => $webhookUrl,
                'byEvents' => false,
                'base64'   => true,
                'events'   => [
                    'MESSAGES_UPSERT',
                    'MESSAGES_UPDATE',
                    'CONNECTION_UPDATE',
                    'SEND_MESSAGE',
                ],
            ],
        ])->throw()->json();
    }

    public function connect(string $instance): array
    {
        return $this->client()->get("/instance/connect/{$instance}")->throw()->json();
    }

    public function connectionState(string $instance): array
    {
        return $this->client()->get("/instance/connectionState/{$instance}")->throw()->json();
    }

    public function deleteInstance(string $instance): array
    {
        return $this->client()->delete("/instance/delete/{$instance}")->json() ?? [];
    }

    public function sendText(string $instance, string $to, string $text): array
    {
        return $this->client()->post("/message/sendText/{$instance}", [
            'number' => $to,
            'text'   => $text,
        ])->throw()->json();
    }

    // Pergunta ao WhatsApp se os numeros existem e devolve o JID canonico.
    // No Brasil isso e o que resolve o nono digito: (84) 9614-3373 digitado a
    // mao pode virar 5584996143373 de verdade, e so o WhatsApp sabe qual e.
    public function checkNumbers(string $instance, array $numbers): array
    {
        return $this->client()
            ->post("/chat/whatsappNumbers/{$instance}", ['numbers' => $numbers])
            ->throw()->json();
    }

    public function instanceInfo(string $instance): array
    {
        return $this->client()
            ->get('/instance/fetchInstances', ['instanceName' => $instance])
            ->throw()->json();
    }

    // Baixa o binario de uma mensagem de midia recebida. O webhook nao traz o
    // arquivo, so o aviso de que existe.
    public function getMediaBase64(string $instance, string $messageId): array
    {
        return $this->client()->timeout(120)
            ->post("/chat/getBase64FromMediaMessage/{$instance}", [
                'message'      => ['key' => ['id' => $messageId]],
                'convertToMp4' => false,
            ])->throw()->json();
    }

    public function sendMedia(
        string $instance,
        string $to,
        string $mediatype,
        string $base64,
        ?string $caption = null,
        ?string $fileName = null,
        ?string $mimetype = null,
    ): array {
        return $this->client()->timeout(180)
            ->post("/message/sendMedia/{$instance}", array_filter([
                'number'    => $to,
                'mediatype' => $mediatype,
                'mimetype'  => $mimetype,
                'caption'   => $caption,
                'media'     => $base64,
                'fileName'  => $fileName,
            ], fn ($v) => $v !== null && $v !== ''))->throw()->json();
    }

    // Endpoint separado de proposito: o sendMedia manda audio como arquivo
    // anexado; so este faz virar nota de voz.
    public function sendAudio(string $instance, string $to, string $base64): array
    {
        return $this->client()->timeout(180)
            ->post("/message/sendWhatsAppAudio/{$instance}", [
                'number' => $to,
                'audio'  => $base64,
            ])->throw()->json();
    }

    public function groupInfo(string $instance, string $groupJid): array
    {
        return $this->client()
            ->get("/group/findGroupInfos/{$instance}", ['groupJid' => $groupJid])
            ->throw()->json();
    }

    /**
     * Avisa o WhatsApp que estas mensagens foram lidas, para o cliente ver os
     * dois tiques azuis. Sem isso ele manda mensagem, o atendente le na tela, e
     * do lado dele continua parecendo que ninguem viu.
     *
     * @param  array<int, array{remoteJid: string, fromMe: bool, id: string}>  $mensagens
     */
    public function marcarComoLida(string $instance, array $mensagens): array
    {
        if ($mensagens === []) {
            return [];
        }

        return $this->client()
            ->post("/chat/markMessageAsRead/{$instance}", ['readMessages' => $mensagens])
            ->throw()
            ->json() ?? [];
    }
}
