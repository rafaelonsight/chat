<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Unico arquivo do OnChat que conhece o servidor de midia.
 *
 * O resto do sistema pede um token e recebe uma string. Trocar de servidor de video um dia —
 * ou sair do proprio para um servico de fora — para aqui dentro.
 *
 * SEM SDK: o LiveKit fala JWT assinado com HS256 e um punhado de chamadas HTTP. Uma
 * dependencia a mais no composer, que precisa acompanhar upgrade de PHP e de framework, custa
 * mais caro que as trinta linhas abaixo.
 */
class Livekit
{
    /**
     * Janela para ENTRAR na sala, e nao duracao da reuniao.
     *
     * O servidor de midia confere o token uma vez, no momento em que o navegador conecta: quem
     * ja esta dentro continua dentro depois de o token vencer, entao encurtar isto nao derruba
     * reuniao longa. O que encurta e o prazo em que um link vazado ainda serve para entrar.
     */
    public const MINUTOS_DO_TOKEN = 30;

    /**
     * Quanto tempo a sala vazia sobrevive no servidor de midia.
     *
     * Sao dois lados da mesma coisa e o LiveKit conta separado: sala que ninguem chegou a
     * abrir, e sala depois que o ultimo saiu. Configurar so o primeiro e o engano facil — a
     * reuniao que acabou cairia no padrao do servidor, que nao e o numero que escolhemos.
     */
    public const SEGUNDOS_SALA_VAZIA = 300;

    public function configurado(): bool
    {
        return (bool) ($this->url() && $this->chave() && $this->segredo());
    }

    /** O endereco que o NAVEGADOR usa, por WebSocket. */
    public function url(): string
    {
        return (string) config('services.livekit.url');
    }

    /**
     * O token que o navegador entrega ao servidor de midia.
     *
     * Anfitriao ganha o direito de encerrar a sala para todo mundo; convidado, nao. E a unica
     * diferenca entre os dois, e ela mora no token — nao na tela, que qualquer um reescreve.
     */
    public function tokenDeSala(string $sala, string $identidade, string $nome, bool $anfitriao = false): string
    {
        $video = [
            'room'           => $sala,
            'roomJoin'       => true,
            'canPublish'     => true,
            'canSubscribe'   => true,
            'canPublishData' => true,
        ];

        if ($anfitriao) {
            $video['roomAdmin'] = true;
        }

        return $this->jwt($video, self::MINUTOS_DO_TOKEN * 60, $identidade, $nome);
    }

    /**
     * Cria a sala com os limites que o servidor de midia passa a impor sozinho.
     *
     * Sem isto o LiveKit cria a sala no primeiro participante, com os padroes da instalacao:
     * sem teto de gente e com o tempo de sala vazia que vier. E o teto so vale de verdade aqui
     * — contar participantes no PHP antes de deixar entrar nao fecha corrida nenhuma, porque
     * dois convidados no mesmo instante leem o mesmo "antes".
     */
    public function criarSala(string $sala, int $maxParticipantes): void
    {
        $this->chamar('CreateRoom', [
            'name'             => $sala,
            'emptyTimeout'     => self::SEGUNDOS_SALA_VAZIA,
            'departureTimeout' => self::SEGUNDOS_SALA_VAZIA,
            'maxParticipants'  => max(2, $maxParticipantes),
        ]);
    }

    /** Derruba todo mundo e apaga a sala no servidor de midia. */
    public function encerrarSala(string $sala): void
    {
        $this->chamar('DeleteRoom', ['room' => $sala], sala: $sala, tolerarSumida: true);
    }

    /**
     * Cala o microfone de alguem.
     *
     * QUEM CALA E O SERVIDOR DE MIDIA, e nao um recado para o navegador da pessoa. A diferenca
     * importa: pedir por mensagem depende do outro lado obedecer, e o caso em que isto e usado
     * — microfone aberto num ambiente barulhento, alguem que esqueceu que estava ligado — e
     * justamente quando a pessoa nao esta olhando para a tela.
     *
     * O silencio nao e definitivo: quem foi calado pode ligar o microfone de novo. Isso e de
     * proposito — a ferramenta e para resolver barulho, nao para tirar a voz de alguem.
     */
    public function silenciarParticipante(string $sala, string $identidade): bool
    {
        $r = $this->chamar('ListParticipants', ['room' => $sala], sala: $sala, tolerarSumida: true);

        foreach ($r['participants'] ?? [] as $p) {
            if (($p['identity'] ?? null) !== $identidade) {
                continue;
            }

            foreach ($p['tracks'] ?? [] as $faixa) {
                // MICROPHONE e nao AUDIO: audio tambem e o som de uma tela compartilhada, e
                // calar a apresentacao de alguem achando que era o microfone dele seria pior
                // que nao fazer nada.
                if (($faixa['source'] ?? null) !== 'MICROPHONE' || empty($faixa['sid'])) {
                    continue;
                }

                $this->chamar('MutePublishedTrack', [
                    'room'      => $sala,
                    'identity'  => $identidade,
                    'track_sid' => $faixa['sid'],
                    'muted'     => true,
                ], sala: $sala, tolerarSumida: true);

                return true;
            }
        }

        return false;
    }

    /**
     * Tira alguem da sala.
     *
     * A pessoa cai na hora e o token dela nao serve mais para aquela sessao — mas o LINK
     * continua valendo. Quem chama precisa saber disso: tirar alguem que nao devia estar ali e
     * so metade do trabalho se o link ainda abre a porta.
     */
    public function removerParticipante(string $sala, string $identidade): void
    {
        $this->chamar('RemoveParticipant', [
            'room'     => $sala,
            'identity' => $identidade,
        ], sala: $sala, tolerarSumida: true);
    }

    public function contarParticipantes(string $sala): int
    {
        $r = $this->chamar('ListParticipants', ['room' => $sala], sala: $sala, tolerarSumida: true);

        return count($r['participants'] ?? []);
    }

    // ------------------------------------------------------------------ tripas

    private function chave(): string
    {
        return (string) config('services.livekit.key');
    }

    private function segredo(): string
    {
        return (string) config('services.livekit.secret');
    }

    /**
     * Por onde o PHP administra as salas.
     *
     * Quando ha endereco interno, e ele: o servidor de midia roda na propria maquina, e
     * mandar a administracao dar a volta pela internet obrigaria a publicar uma API que nao
     * precisa ser publica.
     *
     * Sem ele, deriva do endereco do navegador. A troca de esquema e obrigatoria: sinalizacao
     * e wss:// porque o navegador fala WebSocket, mas a API fala HTTP no mesmo host, e o
     * cliente HTTP recusa a URL antes mesmo de sair da maquina.
     */
    private function urlHttp(): string
    {
        $interno = (string) config('services.livekit.api_url');

        if ($interno !== '') {
            return rtrim($interno, '/');
        }

        return (string) preg_replace('/^ws/', 'http', rtrim($this->url(), '/'));
    }

    /**
     * Sala so existe no servidor de midia depois que alguem conecta.
     *
     * Perguntar por uma sala que ninguem abriu devolve "not_found", e isso e o estado
     * esperado, nao falha. A checagem e por FORMATO e nao por classe de excecao de proposito:
     * "nao existe" virar erro 500 quebraria a tela de quem so queria entrar.
     */
    private function ehSumida(int $status, array $corpo): bool
    {
        return $status === 404 || ($corpo['code'] ?? null) === 'not_found';
    }

    private function chamar(string $metodo, array $corpo, ?string $sala = null, bool $tolerarSumida = false): array
    {
        if (! $this->configurado()) {
            throw new RuntimeException('Vídeo não configurado: faltam as credenciais do servidor de mídia.');
        }

        $resposta = Http::withToken($this->jwtDeAdmin($sala))
            ->acceptJson()
            ->timeout(8)
            ->post($this->urlHttp().'/twirp/livekit.RoomService/'.$metodo, $corpo);

        $dados = $resposta->json() ?? [];

        if ($resposta->successful()) {
            return is_array($dados) ? $dados : [];
        }

        if ($tolerarSumida && $this->ehSumida($resposta->status(), $dados)) {
            return [];
        }

        throw new RuntimeException(
            'Servidor de vídeo recusou '.$metodo.': '.($dados['msg'] ?? $resposta->status()),
        );
    }

    /**
     * O token que administra salas.
     *
     * CRIAR uma sala e permissao geral; MEXER numa sala existente — apagar, listar quem esta
     * dentro — e permissao POR SALA, e o servidor recusa com "permissions denied" se o nome
     * dela nao vier no proprio token. O erro nao diz qual permissao faltou, entao vale
     * lembrar: se uma chamada nova de administracao falhar assim, e isto.
     */
    private function jwtDeAdmin(?string $sala = null): string
    {
        $video = ['roomCreate' => true, 'roomAdmin' => true, 'roomList' => true];

        if ($sala !== null) {
            $video['room'] = $sala;
        }

        return $this->jwt($video, 60);
    }

    /**
     * JWT HS256 na mao.
     *
     * O `nbf` sai dez segundos no passado: relogio de servidor e de cliente nunca batem no
     * milissegundo, e um token "ainda nao valido" por dois segundos e recusado sem explicacao
     * nenhuma do outro lado.
     */
    private function jwt(array $video, int $ttlSegundos, ?string $identidade = null, ?string $nome = null): string
    {
        $agora = time();

        $carga = array_filter([
            'iss'   => $this->chave(),
            'sub'   => $identidade,
            'name'  => $nome,
            'nbf'   => $agora - 10,
            'exp'   => $agora + $ttlSegundos,
            'video' => $video,
        ], fn ($v) => $v !== null);

        $partes = [
            $this->base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            $this->base64url(json_encode($carga)),
        ];

        $partes[] = $this->base64url(
            hash_hmac('sha256', implode('.', $partes), $this->segredo(), true),
        );

        return implode('.', $partes);
    }

    private function base64url(string $bruto): string
    {
        return rtrim(strtr(base64_encode($bruto), '+/', '-_'), '=');
    }
}
