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

    public function nome(): string
    {
        return 'meta_cloud';
    }

    public function texto(Channel $canal, string $destino, string $texto): array
    {
        $r = $this->cliente($canal)
            ->post($this->url($canal).'/messages', [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => self::soDigitos($destino),
                'type'              => 'text',
                'text'              => [
                    // Sem previa de link: a Meta busca a pagina para montar o cartao, e
                    // isso atrasa o envio e vaza para o servidor deles qual link o
                    // atendente mandou. Quem quiser previa liga depois, sabendo.
                    'preview_url' => false,
                    'body'        => $texto,
                ],
            ])
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
