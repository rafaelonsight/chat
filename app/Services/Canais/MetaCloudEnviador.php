<?php

namespace App\Services\Canais;

use App\Models\Channel;
use App\Models\MetaTemplate;
use Illuminate\Support\Facades\Http;

/**
 * Envio pela API oficial da Meta (WhatsApp Cloud API).
 *
 * Duas diferencas em relacao a Evolution que o codigo tem de respeitar:
 *
 * 1. O destino vai SEM o sinal de mais. A Meta recusa "+5584..." com erro de
 *    parametro, e o erro nao diz que o problema e o sinal — dava um dia de caca.
 * 2. Texto livre so sai dentro da janela de 24h. Isso NAO e checado aqui de proposito:
 *    a trava vive no job de envio, que e quem sabe se vale a pena tentar e o que fazer
 *    quando nao vale. Enviador tenta e relata.
 */
class MetaCloudEnviador implements Enviador
{
    public function __construct(
        /** Reserva, do .env: vale para canal que ainda nao tem credencial propria. */
        private readonly string $tokenPadrao,
        private readonly string $versao,
        private readonly int $timeout,
    ) {}

    public function reagir(Channel $canal, string $destino, array $alvo, string $emoji): void
    {
        $this->cliente($canal)
            ->post($this->url($canal).'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => self::soDigitos($destino),
                'type'              => 'reaction',
                'reaction'          => [
                    'message_id' => $alvo['external_id'],
                    // String vazia e como a Meta entende "tirar a reacao". Mandar null aqui
                    // volta erro 100 dizendo que o parametro e invalido.
                    'emoji'      => $emoji,
                ],
            ])
            ->throw();
    }

    public function nome(): string
    {
        return 'meta_cloud';
    }

    public function texto(Channel $canal, string $destino, string $texto, ?array $citar = null): array
    {
        $r = $this->cliente($canal)
            ->post($this->url($canal).'/messages', array_filter([
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => self::soDigitos($destino),
                // A Meta chama a citacao de "context", e quer so o wamid. Se o wamid nao
                // existir mais do lado dela — mensagem antiga demais — ela recusa o envio
                // inteiro com erro 131009, e nao apenas ignora a citacao.
                'context'           => $citar ? ['message_id' => $citar['external_id']] : null,
                'type'              => 'text',
                'text'              => [
                    // Sem previa de link: a Meta busca a pagina para montar o cartao, e
                    // isso atrasa o envio e vaza para o servidor deles qual link o
                    // atendente mandou. Quem quiser previa liga depois, sabendo.
                    'preview_url' => false,
                    'body'        => $texto,
                ],
            ], fn ($v) => $v !== null))
            ->throw()
            ->json();

        return ['external_id' => data_get($r, 'messages.0.id')];
    }

    public function marcarLida(Channel $canal, string $jid, array $externalIds): void
    {
        // SO A MAIS NOVA. No WhatsApp, marcar uma mensagem como lida marca as anteriores
        // da conversa junto — e a semantica do proprio aplicativo, nao um atalho nosso.
        // Uma chamada por mensagem daria o mesmo resultado gastando 50 vezes mais cota.
        $ids = array_values(array_filter($externalIds));

        if ($ids === []) {
            return;
        }

        $this->cliente($canal)
            ->post($this->url($canal).'/messages', [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $ids[count($ids) - 1],
            ])
            ->throw();
    }

    /**
     * O numero na URL e o Phone Number ID, nao o telefone.
     *
     * Fica no CANAL e nao na configuracao global porque cada cliente vai ter o proprio
     * numero: com isso no .env, o segundo cliente nao caberia.
     */
    private function url(Channel $canal): string
    {
        $id = trim((string) $canal->meta_phone_number_id);

        if ($id === '') {
            throw new ConfiguracaoInvalida(
                "O canal \"{$canal->nome}\" e do tipo oficial mas nao tem Phone Number ID."
            );
        }

        return "https://graph.facebook.com/{$this->versao}/{$id}";
    }

    /**
     * Envia um template aprovado.
     *
     * E o unico caminho depois de a janela de 24h fechar — e por isso este metodo NAO
     * checa a janela: checar aqui negaria justamente o uso que ele existe para atender.
     *
     * O que vai para a Meta e o NOME do template mais os parametros posicionais. O texto
     * montado nunca e enviado: quem monta o texto final e o WhatsApp, a partir do template
     * que ele mesmo aprovou. Mandar o texto seria mandar mensagem livre com outro nome — e
     * a Meta recusaria, com razao.
     *
     * @param  array<int, string>  $valores  na ordem de {{1}}, {{2}}, ...
     * @return array{external_id: ?string}
     */
    public function template(Channel $canal, string $destino, MetaTemplate $modelo, array $valores = []): array
    {
        $motivo = $modelo->porQueNaoPodeEnviar();

        if ($motivo !== null) {
            throw new \RuntimeException("O template \"{$modelo->nome}\" nao pode ser enviado: {$motivo}.");
        }

        $valores = array_values($valores);

        if (count($valores) !== (int) $modelo->variaveis) {
            throw new \RuntimeException(
                "O template \"{$modelo->nome}\" precisa de {$modelo->variaveis} valor(es) e recebeu ".count($valores).'.'
            );
        }

        foreach ($valores as $i => $valor) {
            $valor = (string) $valor;
            $posicao = $i + 1;

            // A Meta recusa parametro vazio, com quebra de linha, com tabulacao ou com
            // quatro espacos seguidos. Recusar aqui devolve uma frase que o atendente
            // entende e diz QUAL valor esta errado; deixar passar devolve o erro 132000
            // da Meta, que nao diz qual e.
            if (trim($valor) === '') {
                throw new \RuntimeException("O valor {$posicao} do template \"{$modelo->nome}\" esta vazio.");
            }

            if (preg_match('/[\r\n\t]|\s{4,}/', $valor)) {
                throw new \RuntimeException(
                    "O valor {$posicao} do template \"{$modelo->nome}\" tem quebra de linha, tabulacao ou espacos demais: a Meta recusa."
                );
            }
        }

        $corpo = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => self::soDigitos($destino),
            'type'              => 'template',
            'template'          => [
                'name'     => $modelo->nome,
                // O idioma faz parte da identidade: o mesmo nome existe em varios idiomas
                // e sao templates diferentes. Errar aqui devolve "template does not exist".
                'language' => ['code' => $modelo->idioma],
            ],
        ];

        if ($valores !== []) {
            $corpo['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(
                    fn ($valor) => ['type' => 'text', 'text' => (string) $valor],
                    $valores,
                ),
            ]];
        }

        $r = $this->cliente($canal)
            ->post($this->url($canal).'/messages', $corpo)
            ->throw()
            ->json();

        return ['external_id' => data_get($r, 'messages.0.id')];
    }

    /**
     * Envia arquivo. SAO DUAS CHAMADAS, e nao uma.
     *
     * Na API oficial nao existe mandar bytes junto da mensagem: sobe-se o arquivo primeiro,
     * a Meta devolve um id, e a mensagem referencia esse id. Quem espera um unico POST
     * como na Evolution perde tempo procurando o parametro que nao existe.
     *
     * @param  array{tipo: string, bytes: string, mime: ?string, nome: ?string, legenda: ?string}  $arquivo
     * @return array{external_id: ?string}
     */
    public function midia(Channel $canal, string $destino, array $arquivo, ?array $citar = null): array
    {
        $tipo = (string) $arquivo['tipo'];
        $mime = (string) ($arquivo['mime'] ?: 'application/octet-stream');

        // PASSO 1: subir o arquivo. Cliente PROPRIO, sem asJson — ver clienteDeArquivo().
        $subida = $this->clienteDeArquivo($canal)
            ->attach('file', $arquivo['bytes'], $arquivo['nome'] ?: 'arquivo', ['Content-Type' => $mime])
            ->post($this->url($canal).'/media', [
                'messaging_product' => 'whatsapp',
                'type'              => $mime,
            ])
            ->throw()
            ->json();

        $id = (string) data_get($subida, 'id');

        if ($id === '') {
            throw new \RuntimeException('a Meta aceitou o arquivo mas nao devolveu o id da midia.');
        }

        // PASSO 2: a mensagem que aponta para o arquivo.
        //
        // Cada tipo aceita campos diferentes, e mandar campo que o tipo nao aceita volta
        // como erro 100 "param is not valid" — que nao diz qual param. Audio nao aceita
        // legenda; figurinha nao aceita nada alem do id.
        $conteudo = ['id' => $id];

        if (($arquivo['legenda'] ?? null) && in_array($tipo, ['image', 'video', 'document'], true)) {
            $conteudo['caption'] = $arquivo['legenda'];
        }

        if ($tipo === 'document' && ($arquivo['nome'] ?? null)) {
            // Sem filename o cliente recebe o documento com um nome gerado pela Meta, e
            // "attachment-1.pdf" no lugar de "contrato.pdf" gera pergunta no atendimento.
            $conteudo['filename'] = $arquivo['nome'];
        }

        $r = $this->cliente($canal)
            ->post($this->url($canal).'/messages', array_filter([
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => self::soDigitos($destino),
                'context'           => $citar ? ['message_id' => $citar['external_id']] : null,
                'type'              => $tipo,
                $tipo               => $conteudo,
            ], fn ($v) => $v !== null))
            ->throw()
            ->json();

        return ['external_id' => data_get($r, 'messages.0.id')];
    }

    /**
     * A API oficial NAO tem como perguntar se um numero tem WhatsApp.
     *
     * Devolver null e a resposta honesta. A tentacao seria mandar uma mensagem de teste para
     * descobrir — o que gastaria dinheiro, apareceria no aparelho de quem talvez nem seja
     * cliente, e contaria como iniciativa da empresa. Quando o numero nao existe, a Meta
     * responde 131026 no envio, e o atendente ve a falha na propria bolha.
     */
    public function verificarNumero(Channel $canal, string $e164): array
    {
        return ['existe' => null, 'e164' => null];
    }

    /**
     * Baixa uma midia recebida.
     *
     * SAO DUAS CHAMADAS, e nao uma. A primeira pergunta a Meta onde esta o arquivo; a
     * segunda busca os bytes na URL que ela devolveu. Nao ha atalho: o webhook entrega
     * apenas um id.
     *
     * Dois detalhes que custam tempo de investigacao quando faltam:
     *
     * 1. A URL devolvida vive POUCOS MINUTOS. Guardar essa URL no banco para baixar
     *    depois nao funciona — por isso baixamos na hora, e o que guardamos e o arquivo.
     * 2. A URL exige o MESMO cabecalho Authorization. Ela e de outro dominio
     *    (lookaside.fbsbx.com) e parece publica, mas sem o token devolve 401 — e o erro
     *    nao diz que falta credencial.
     *
     * @return array{bytes: string, mime: string, tamanho: int}
     */
    public function baixarMidia(Channel $canal, string $mediaId): array
    {
        $meta = $this->cliente($canal)
            ->get("https://graph.facebook.com/{$this->versao}/{$mediaId}")
            ->throw()
            ->json();

        $url = (string) data_get($meta, 'url');

        if ($url === '') {
            throw new \RuntimeException('a Meta nao devolveu a URL da midia '.$mediaId);
        }

        $arquivo = $this->cliente($canal)
            // Sem asJson aqui: a resposta e binaria. E com User-Agent porque o
            // lookaside recusa cliente sem identificacao com 403.
            ->withHeaders(['User-Agent' => 'OnChat/1.0'])
            ->get($url)
            ->throw();

        return [
            'bytes'   => $arquivo->body(),
            'mime'    => (string) (data_get($meta, 'mime_type') ?: 'application/octet-stream'),
            'tamanho' => (int) (data_get($meta, 'file_size') ?: strlen($arquivo->body())),
        ];
    }

    /**
     * Lista os templates da WABA deste canal.
     *
     * Pagina por CURSOR, e nao seguindo o link "next" que a Meta devolve: aquele link vem
     * com o access_token embutido na propria URL, e passear com token dentro de string de
     * URL e como esse segredo acaba em log de servidor.
     *
     * @return array{ok: bool, erro?: string, templates?: array<int, array<string, mixed>>}
     */
    public function templates(Channel $canal, int $paginas = 10): array
    {
        $todos = [];
        $depois = null;

        try {
            for ($i = 0; $i < $paginas; $i++) {
                $r = $this->cliente($canal)
                    ->get($this->urlWaba($canal).'/message_templates', array_filter([
                        'limit'  => 100,
                        'after'  => $depois,
                        'fields' => 'id,name,language,category,status,components',
                    ]))
                    ->throw()
                    ->json();

                foreach ((array) data_get($r, 'data', []) as $template) {
                    $todos[] = $template;
                }

                $depois = data_get($r, 'paging.cursors.after');

                // Sem cursor ou sem "next": acabou. O limite de paginas e trava contra
                // resposta torta que devolvesse cursor para sempre.
                if (! $depois || ! data_get($r, 'paging.next')) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => mb_substr($e->getMessage(), 0, 300)];
        }

        return ['ok' => true, 'templates' => $todos];
    }

    /**
     * Template vive na CONTA (WABA), nao no numero.
     *
     * Dois numeros da mesma conta compartilham os mesmos templates — por isso esta URL usa
     * o WABA ID e nao o Phone Number ID.
     */
    private function urlWaba(Channel $canal): string
    {
        $id = trim((string) $canal->meta_waba_id);

        if ($id === '') {
            throw new ConfiguracaoInvalida(
                "O canal \"{$canal->nome}\" nao tem WABA ID, e template vive na conta e nao no numero."
            );
        }

        return "https://graph.facebook.com/{$this->versao}/{$id}";
    }

    /**
     * Confere a configuracao contra a Meta, SEM enviar mensagem.
     *
     * Nao entra na interface Enviador de proposito: e pergunta do caminho oficial. A
     * Evolution ja tem o QR Code para a mesma finalidade — provar que o canal esta de pe —
     * e aquilo nao cabe nesta forma.
     *
     * Devolve resultado em vez de estourar: quem chama e tela, e tela precisa mostrar o
     * motivo ao atendente, nao uma pagina de erro.
     *
     * @return array{ok: bool, erro?: string, numero?: string, nome?: string, qualidade?: string, situacao?: string}
     */
    public function conferir(Channel $canal): array
    {
        try {
            $r = $this->cliente($canal)
                ->get($this->url($canal), [
                    'fields' => 'display_phone_number,verified_name,quality_rating,status',
                ])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => mb_substr($e->getMessage(), 0, 300)];
        }

        return [
            'ok'        => true,
            'numero'    => (string) data_get($r, 'display_phone_number', '—'),
            'nome'      => (string) data_get($r, 'verified_name', '—'),
            'qualidade' => (string) data_get($r, 'quality_rating', '—'),
            'situacao'  => (string) data_get($r, 'status', '—'),
        ];
    }

    /**
     * O token do CANAL, com o do .env como reserva.
     *
     * No modelo em que cada cliente traz o proprio numero, a credencial e por WABA: quem
     * autoriza e o cliente, e a Meta emite o token para a conta dele. Token global so
     * funciona enquanto o numero e nosso — o segundo cliente nao cabe.
     *
     * Falta dos DOIS estoura: canal oficial sem credencial nenhuma nao pode "quase
     * funcionar" mandando com a credencial de outro.
     */
    private function tokenDe(Channel $canal): string
    {
        $doCanal = trim((string) $canal->meta_token);

        if ($doCanal !== '') {
            return $doCanal;
        }

        if (trim($this->tokenPadrao) === '') {
            throw new ConfiguracaoInvalida(
                "O canal \"{$canal->nome}\" nao tem token da Meta e nao existe token padrao configurado."
            );
        }

        return $this->tokenPadrao;
    }

    /**
     * Cliente para subir arquivo. Igual ao outro, MENOS o asJson.
     *
     * asJson() faz duas coisas: escolhe o formato do corpo E fixa o cabecalho
     * Content-Type em application/json. O attach() muda apenas o formato do corpo. Juntos,
     * produzem um corpo multipart anunciado como JSON, sem boundary — e a Meta recusa sem
     * dizer por que.
     *
     * Isso passou pelo meu teste com fake e foi pego por uma assercao que eu quase deixei
     * de escrever: "o pedido de upload e multipart". Sem ela, o defeito so apareceria na
     * primeira foto que um atendente tentasse mandar.
     *
     * Sem Content-Type nosso, o cliente HTTP monta o dele com o boundary correto.
     */
    private function clienteDeArquivo(Channel $canal)
    {
        return Http::withToken($this->tokenDe($canal))
            // Arquivo demora mais que texto: 16 MB de video em rede ruim nao cabe no
            // timeout de uma chamada de texto, e o corte no meio do upload aparece como
            // falha sem causa.
            ->timeout(max($this->timeout, 120))
            ->acceptJson();
    }

    private function cliente(Channel $canal)
    {
        // withToken: o segredo vai no cabecalho Authorization, nunca na URL. URL aparece
        // em log de servidor, em historico de proxy e em mensagem de excecao.
        return Http::withToken($this->tokenDe($canal))
            ->timeout($this->timeout)
            ->acceptJson()
            // Sem retentativa aqui: o job de envio ja tem backoff, e retentar em dois
            // lugares multiplica a espera sem ninguem perceber.
            ->asJson();
    }

    public static function soDigitos(string $numero): string
    {
        return preg_replace('/\D+/', '', $numero) ?? '';
    }
}
