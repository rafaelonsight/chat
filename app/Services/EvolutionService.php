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

    /**
     * Os avisos que pedimos a Evolution.
     *
     * Constante, e nao lista escrita em cada chamada: criar e reapontar precisam pedir
     * exatamente o mesmo conjunto. Se as duas listas puderem divergir, um dia o reapontamento
     * vai apagar em silencio um tipo de aviso que o sistema depende de receber.
     */
    private const EVENTOS = [
        'MESSAGES_UPSERT',
        'MESSAGES_UPDATE',
        'CONNECTION_UPDATE',
        'SEND_MESSAGE',
    ];

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
                'events'   => self::EVENTOS,
            ],
        ])->throw()->json();
    }

    /**
     * Para onde esta instancia avisa hoje.
     *
     * Sem throw: instancia que nunca teve aviso configurado responde 404, e isso e uma
     * resposta legitima ("nao aponta para lugar nenhum"), nao um erro de comunicacao. Quem
     * chama precisa distinguir as duas coisas, e uma excecao aqui misturaria as duas.
     */
    public function acharWebhook(string $instance): array
    {
        $dados = $this->client()->get("/webhook/find/{$instance}")->json();

        return is_array($dados) ? $dados : [];
    }

    /**
     * Manda esta instancia avisar aqui.
     *
     * O endereco vive DENTRO da Evolution, gravado uma vez quando o canal nasceu. Trocar o
     * dominio do painel nao muda o que ela guardou — e foi exatamente assim que o sistema
     * ficou dois dias surdo, recebendo 302 do endereco velho a cada mensagem de cliente.
     */
    public function apontarWebhook(string $instance, string $url): array
    {
        return $this->client()->post("/webhook/set/{$instance}", [
            'webhook' => [
                'enabled'  => true,
                'url'      => $url,
                'byEvents' => false,
                'base64'   => true,
                'events'   => self::EVENTOS,
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

    public function sendText(string $instance, string $to, string $text, ?array $quoted = null): array
    {
        return $this->client()->post("/message/sendText/{$instance}", array_filter([
            'number' => $to,
            'text'   => $text,
            'quoted' => $quoted,
        ], fn ($v) => $v !== null))->throw()->json();
    }

    /**
     * Monta o "quoted" no formato do Baileys.
     *
     * As tres partes da chave importam: o id diz QUAL mensagem, o fromMe diz de que lado ela
     * esta — o mesmo id existe nos dois sentidos — e o remoteJid diz em qual conversa. O corpo
     * vai junto porque o Baileys desenha a previa com o texto que recebe; sem ele, a citacao
     * chega como uma faixa cinza vazia no aparelho do cliente.
     *
     * @param  array{external_id: string, texto: ?string, minha: bool}  $citar
     */
    /**
     * Reage a uma mensagem.
     *
     * A chave e a mesma do quoted, e pelo mesmo motivo: sem o fromMe o Baileys procura a
     * mensagem no lado errado e a reacao nao aparece em lugar nenhum — sem erro nenhum, o que
     * e o pior desfecho.
     *
     * @param  array{external_id: string, minha: bool}  $alvo
     */
    public function sendReaction(string $instance, string $to, array $alvo, string $emoji): array
    {
        return $this->client()->post("/message/sendReaction/{$instance}", [
            'key' => [
                'id'        => $alvo['external_id'],
                'fromMe'    => $alvo['minha'],
                'remoteJid' => self::jid($to),
            ],
            'reaction' => $emoji,
        ])->throw()->json();
    }

    /**
     * Apaga para todos.
     *
     * @param  array{external_id: string, minha: bool}  $alvo
     */
    public function deleteMessage(string $instance, string $to, array $alvo): array
    {
        return $this->client()->delete("/chat/deleteMessageForEveryone/{$instance}", [
            'id'        => $alvo['external_id'],
            'fromMe'    => $alvo['minha'],
            'remoteJid' => self::jid($to),
        ])->throw()->json();
    }

    /**
     * Transforma o destino num JID de verdade.
     *
     * ISTO EXISTE POR UM ERRO QUE CUSTOU UM ENVIO REAL. O destino que o OnChat usa para enviar
     * e o telefone em E.164, com o sinal de mais: "+554396386381". Para MANDAR mensagem a
     * Evolution aceita isso e normaliza sozinha. Mas dentro de uma CHAVE de mensagem — citar,
     * reagir, apagar — o valor vai direto para o Baileys, que tenta decodificar como JID,
     * nao consegue e devolve TypeError com 500.
     *
     * O sintoma enganava: em conversa de GRUPO tudo funcionava, porque ali o destino ja e um
     * JID (...@g.us). So quebrava no atendimento individual, que e a maioria.
     */
    /**
     * Presenca no chat: composing (digitando), paused (parou), available.
     *
     * timeout curto de proposito: isto e chamado enquanto a pessoa digita, e uma espera de
     * trinta segundos aqui seguraria a requisicao do navegador por um enfeite.
     */
    public function sendPresence(string $instance, string $to, string $presenca): array
    {
        return $this->client()->timeout(5)->post("/chat/sendPresence/{$instance}", [
            'number'   => self::jid($to),
            'delay'    => 1200,
            'presence' => $presenca,
        ])->throw()->json();
    }

    public static function jid(string $destino): string
    {
        if (str_contains($destino, '@')) {
            return $destino;
        }

        return preg_replace('/\D+/', '', $destino).'@s.whatsapp.net';
    }

    public static function quoted(array $citar, string $remoteJid): array
    {
        return [
            'key' => [
                'id'        => $citar['external_id'],
                'fromMe'    => $citar['minha'],
                'remoteJid' => self::jid($remoteJid),
            ],
            'message' => ['conversation' => (string) ($citar['texto'] ?? '')],
        ];
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
        ?array $quoted = null,
    ): array {
        return $this->client()->timeout(180)
            ->post("/message/sendMedia/{$instance}", array_filter([
                'number'    => $to,
                'mediatype' => $mediatype,
                'mimetype'  => $mimetype,
                'caption'   => $caption,
                'media'     => $base64,
                'fileName'  => $fileName,
                'quoted'    => $quoted,
            ], fn ($v) => $v !== null && $v !== ''))->throw()->json();
    }

    // Endpoint separado de proposito: o sendMedia manda audio como arquivo
    // anexado; so este faz virar nota de voz.
    public function sendAudio(string $instance, string $to, string $base64, ?array $quoted = null): array
    {
        return $this->client()->timeout(180)
            ->post("/message/sendWhatsAppAudio/{$instance}", array_filter([
                'number' => $to,
                'audio'  => $base64,
                'quoted' => $quoted,
            ], fn ($v) => $v !== null))->throw()->json();
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
