<?php

namespace App\Models;

use App\Exceptions\PropostaEnviadaProtegida;
use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Uma proposta comercial.
 *
 * E UMA PAGINA COM LINK, e nao um arquivo. Por isso ela guarda token, visualizacoes e aceite:
 * saber que o cliente abriu quatro vezes e parou no preco diz a hora de ligar, e isso um PDF
 * anexado nunca contou.
 */
#[Fillable([
    'tenant_id', 'titulo', 'contact_id', 'cliente_nome', 'cliente_email',
    'conversation_id', 'validade', 'desconto', 'blocos', 'criada_por',
    // O valor cheio ao lado do proposto, a condicao de pagamento e os selos da capa.
    'valor_cheio_unico', 'valor_cheio_recorrente', 'vencimento_dia', 'primeiro_pagamento', 'selos',
])]
class Proposal extends Model
{
    use BelongsToTenant;

    public const RASCUNHO = 'rascunho';

    public const ENVIADA = 'enviada';

    public const VISTA = 'vista';

    public const ACEITA = 'aceita';

    public const RECUSADA = 'recusada';

    /**
     * Os padroes TAMBEM aqui, e nao so no banco.
     *
     * O create() nao rele a linha depois de gravar: o padrao da coluna existe no Postgres, mas o
     * objeto em memoria continua com o campo NULO. Foi exatamente o que aconteceu — chamei
     * marcarEnviada() logo depois de criar, ele comparou null com 'rascunho', deu falso, e a
     * proposta ficou em rascunho sem ninguem reclamar. A pagina publica devolvia 404 e o motivo
     * nao aparecia em lugar nenhum.
     *
     * Segunda vez que esta armadilha me pega neste projeto (a primeira foi a sala de espera da
     * reuniao). O padrao declarado nos dois lugares custa duas linhas e fecha a duvida.
     */
    protected $attributes = [
        'status' => self::RASCUNHO,
        'total_unico' => 0,
        'total_recorrente' => 0,
        'desconto' => 0,
    ];

    protected function casts(): array
    {
        return [
            'blocos' => 'array',
            'selos' => 'array',
            'primeiro_pagamento' => 'date',
            'valor_cheio_unico' => 'decimal:2',
            'valor_cheio_recorrente' => 'decimal:2',
            'validade' => 'date',
            'enviada_em' => 'datetime',
            'vista_em' => 'datetime',
            'aceita_em' => 'datetime',
            'recusada_em' => 'datetime',
            'total_unico' => 'decimal:2',
            'total_recorrente' => 'decimal:2',
            'desconto' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        /*
         * PROPOSTA QUE JA SAIU NAO SE APAGA.
         *
         * Ela e um documento na mao do cliente: ele tem o link, talvez o PDF, e o numero num
         * e-mail. Apagar aqui nao apaga lá — cria o caso em que o cliente pergunta pela
         * PROP-2026-002 e ninguem sabe do que ele fala, ou pior, em que outra proposta nasce com
         * aquele mesmo numero e conteudo diferente.
         *
         * Rascunho pode apagar: nunca chegou a ninguem. Para tirar da lista o que ja saiu, o
         * caminho e marcar recusada — que preserva o registro.
         */
        static::deleting(function (self $p) {
            if ($p->enviada_em !== null) {
                throw new PropostaEnviadaProtegida(
                    'A proposta '.$p->numero.' ja foi enviada ao cliente e nao pode ser apagada. '
                    .'Marque como recusada se o negocio nao andou.'
                );
            }
        });

        static::creating(function (self $p) {
            // Aleatorio e nao sequencial: numero de proposta na URL deixaria qualquer um trocar
            // o 14 pelo 13 e ler a proposta do concorrente.
            $p->token ??= Str::random(40);
            $p->numero ??= static::proximoNumero((int) ($p->tenant_id ?: TenantContext::get()));
        });
    }

    /**
     * PROP-2026-014, sequencial por conta e por ano.
     *
     * CONTA O MAIOR EXISTENTE, e nao quantas linhas ha: contar linhas faria a numeracao
     * voltar sozinha ao apagar qualquer uma do meio.
     *
     * E o que garante que um numero JA ENVIADO nunca se repita nao e este metodo — e a guarda
     * de exclusao abaixo, que recusa apagar proposta que ja saiu. Numero de rascunho que morreu
     * antes de sair pode ser reusado sem problema: ele nunca chegou a ninguem.
     *
     * (Escrevi aqui, na primeira versao, que apagar nao fazia a numeracao voltar. Nao era
     * verdade — o teste pegou. Comentario que promete o que o codigo nao faz e pior que
     * comentario nenhum, porque o proximo leitor confia nele.)
     *
     * A corrida de dois cadastros simultaneos e fechada pelo indice unico (tenant_id, numero) —
     * aqui seria impossivel fechar, e o banco fecha de graca. Quem salva trata a colisao.
     */
    /** Os selos da capa: frase curta que responde uma objecao antes de ela ser feita. */
    public const SELOS = [
        'Sem fidelidade',
        'Sem taxa de cancelamento',
        'Implantação acompanhada',
        'Suporte por WhatsApp',
        'Treinamento incluído',
        'Cancelamento com 30 dias de aviso',
    ];

    /**
     * O valor cheio, quando ele for maior que o proposto.
     *
     * A GUARDA E O METODO. Ancora menor que o preco nao e ancora: e erro de digitacao que a
     * pagina transformaria num anuncio de aumento — o cliente leria "de R$ 300 por R$ 500". Na
     * duvida a pagina nao mostra nada, que e sempre melhor que mostrar o contrario.
     *
     * @return array{cheio: float, agora: float, economia: float}|null
     */
    public function ancora(string $qual): ?array
    {
        [$cheio, $agora] = $qual === 'recorrente'
            ? [$this->valor_cheio_recorrente, $this->total_recorrente]
            : [$this->valor_cheio_unico, $this->total_unico];

        $cheio = (float) $cheio;
        $agora = (float) $agora;

        if ($cheio <= 0 || $agora <= 0 || $cheio <= $agora) {
            return null;
        }

        return ['cheio' => $cheio, 'agora' => $agora, 'economia' => $cheio - $agora];
    }

    /**
     * O momento em que a proposta deixa de valer, para o contador da pagina.
     *
     * FIM DO DIA, e nao o comeco dele: quem recebe "valida ate 15/08" espera poder aceitar no dia
     * 15. Um contador que zera a meia-noite do dia 14 cobraria um dia que foi prometido.
     */
    public function venceEm(): ?Carbon
    {
        return $this->validade?->copy()->endOfDay();
    }

    public static function proximoNumero(int $tenantId, ?int $ano = null): string
    {
        $ano ??= (int) now()->format('Y');
        $prefixo = 'PROP-'.$ano.'-';

        $ultimo = static::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('numero', 'like', $prefixo.'%')
            ->orderByRaw('length(numero) desc, numero desc')
            ->value('numero');

        $seq = $ultimo ? ((int) Str::afterLast($ultimo, '-')) + 1 : 1;

        return $prefixo.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProposalItem::class)->orderBy('ordem')->orderBy('id');
    }

    public function visualizacoes(): HasMany
    {
        return $this->hasMany(ProposalView::class)->latest('vista_em');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    /**
     * Recalcula os dois totais a partir dos itens.
     *
     * DOIS, e nao um: ele vende implantacao (uma vez) e mensalidade. Somar as duas coisas daria
     * um numero que nao existe — "R$ 12.000" quando o certo e "R$ 12.000 mais R$ 890 por mes".
     *
     * O desconto sai do total UNICO. Descontar da mensalidade seria dar desconto para sempre, e
     * quem escreve "desconto de R$ 500" quase nunca quer dizer isso.
     */
    public function recalcular(): void
    {
        $unico = 0.0;
        $recorrente = 0.0;

        foreach ($this->itens as $item) {
            $valor = (float) $item->quantidade * (float) $item->valor_unitario;

            if ($item->recorrente) {
                $recorrente += $valor;
            } else {
                $unico += $valor;
            }
        }

        $this->forceFill([
            'total_unico' => max(0, $unico - (float) $this->desconto),
            'total_recorrente' => $recorrente,
        ])->saveQuietly();
    }

    /**
     * Venceu?
     *
     * DERIVADO da data, e nao um status guardado. Status guardado precisaria de uma rotina
     * diaria para virar, e uma rotina que falha deixa proposta vencida parecendo valida — que e
     * exatamente o caso em que alguem aceita um preco de um ano atras.
     */
    public function vencida(): bool
    {
        return $this->validade !== null
            && $this->aceita_em === null
            && $this->validade->endOfDay()->isPast();
    }

    public function podeSerAceita(): bool
    {
        return $this->aceita_em === null
            && $this->recusada_em === null
            && ! $this->vencida()
            && $this->status !== self::RASCUNHO;
    }

    public function marcarEnviada(): void
    {
        if ($this->status === self::RASCUNHO) {
            $this->forceFill(['status' => self::ENVIADA, 'enviada_em' => now()])->save();
        }
    }

    /**
     * Registra uma abertura.
     *
     * Toda abertura vira linha: "abriu quatro vezes" e informacao de venda, "abriu" e so um sim.
     *
     * O status so anda para 'vista' se ainda estava em 'enviada' — sem esse cuidado, o cliente
     * reabrindo a proposta depois de aceitar faria o status voltar atras, e o funil com ele.
     */
    public function registrarVisualizacao(?string $ip, ?string $agente): void
    {
        $this->visualizacoes()->create([
            'vista_em' => now(),
            'ip' => $ip,
            'agente' => Str::limit((string) $agente, 250, ''),
        ]);

        $mudanca = ['vista_em' => $this->vista_em ?? now()];

        if ($this->status === self::ENVIADA) {
            $mudanca['status'] = self::VISTA;
        }

        $this->forceFill($mudanca)->save();
    }

    /**
     * O cliente aceitou.
     *
     * Guarda QUEM digitou o nome, quando, de onde e com qual navegador. Nao e assinatura
     * certificada — e o registro que transforma um "pode fazer" no WhatsApp em algo que se
     * mostra depois, com data e origem.
     *
     * Idempotente: aceitar duas vezes (dois cliques, recarregar a pagina) nao reescreve a data
     * do primeiro aceite, que e a que vale.
     */
    public function aceitar(string $nome, ?string $ip, ?string $agente): void
    {
        /*
         * A GUARDA VIVE AQUI, e nao so na tela.
         *
         * Eu tinha deixado a checagem de validade apenas no componente publico, e o teste
         * pegou: chamar aceitar() direto numa proposta VENCIDA gravava o aceite. A tela
         * estava protegida, o modelo nao — e amanha um comando, uma acao do painel ou uma
         * integracao chamariam por outro caminho e aceitariam preco de meses atras.
         *
         * podeSerAceita() cobre os quatro casos de uma vez: ja aceita, ja recusada, vencida,
         * e ainda em rascunho.
         */
        if (! $this->podeSerAceita()) {
            return;
        }

        $this->forceFill([
            'status' => self::ACEITA,
            'aceita_em' => now(),
            'aceita_por' => Str::limit(trim($nome), 155, ''),
            'aceita_ip' => $ip,
            'aceita_agente' => Str::limit((string) $agente, 250, ''),
        ])->save();

        $this->moverFunil();
    }

    public function recusar(?string $motivo, ?string $ip): void
    {
        if ($this->aceita_em !== null || $this->recusada_em !== null) {
            return;
        }

        $this->forceFill([
            'status' => self::RECUSADA,
            'recusada_em' => now(),
            'recusa_motivo' => $motivo ? Str::limit(trim($motivo), 900, '') : null,
            'aceita_ip' => $ip,
        ])->save();
    }

    /**
     * Leva a conversa do negocio para a etapa que fecha.
     *
     * SILENCIOSO QUANDO NAO HA COMO: proposta sem conversa ligada, ou funil sem etapa de
     * fechamento, simplesmente nao move nada. O aceite do cliente nao pode falhar porque o
     * painel de vendas ainda nao foi configurado.
     */
    private function moverFunil(): void
    {
        $conversa = $this->conversation;

        if (! $conversa) {
            return;
        }

        $etapa = FunnelStage::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant_id)
            ->where('encerra', true)
            ->orderBy('ordem')
            ->first();

        if ($etapa) {
            $conversa->forceFill(['funnel_stage_id' => $etapa->id])->save();
        }
    }
}
