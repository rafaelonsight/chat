<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ      = 'read';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'channel_id', 'direcao',
        'tipo', 'corpo', 'external_id', 'responde_a_id', 'status', 'erro', 'enviada_em',
        'remetente_nome', 'remetente_jid', 'automatica',
        'transcricao', 'transcricao_status',
        'media_path', 'media_mime', 'media_nome', 'media_tamanho', 'media_duracao', 'legenda',
        'lida_em',
    ];

    protected $casts = [
        'enviada_em'     => 'datetime',
        'media_tamanho'  => 'integer',
        'media_duracao'  => 'integer',
        'automatica'     => 'boolean',
        'lida_em'        => 'datetime',
    ];

    protected static function booted(): void
    {
        // A transicao de estado da conversa mora aqui, num lugar so: toda
        // mensagem criada — pela tela, por job ou pela IA no futuro — move a
        // conversa do jeito certo sem depender de alguem lembrar.
        static::created(function (Message $mensagem) {
            // Resposta automatica nao e atendimento: se movesse a conversa para
            // "em atendimento", ela sairia de Novos e ninguem veria o cliente
            // na manha seguinte.
            if ($mensagem->automatica) {
                return;
            }

            $mensagem->conversation?->aoReceberMensagem($mensagem);
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** A mensagem que esta foi responder, quando houve uma. */
    public function respondeA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'responde_a_id');
    }

    /**
     * Acha uma mensagem pelo id que o PROVEDOR deu a ela.
     *
     * withoutGlobalScope porque quem chama e webhook: nao ha usuario logado, e depender de o
     * TenantContext estar preenchido faria a busca voltar vazia em silencio — a citacao
     * simplesmente nao apareceria, sem erro nenhum. O filtro por canal ja isola, e isola
     * melhor: external_id so e unico dentro de um canal.
     */
    public static function acharPorExternalId(int $channelId, ?string $externalId): ?self
    {
        if (! $externalId) {
            return null;
        }

        return static::withoutGlobalScope('tenant')
            ->where('channel_id', $channelId)
            ->where('external_id', $externalId)
            ->first();
    }

    /** Uma linha representando a mensagem numa citacao. Midia nao tem texto, e citacao
     *  vazia parece defeito — entao cada tipo diz o que e. */
    public function resumo(int $limite = 90): string
    {
        $texto = trim((string) ($this->corpo ?: $this->legenda ?: ''));

        if ($texto !== '') {
            return \Illuminate\Support\Str::limit($texto, $limite);
        }

        return match ($this->tipo) {
            'image'    => 'Imagem',
            'video'    => 'Vídeo',
            'audio'    => 'Áudio',
            'sticker'  => 'Figurinha',
            'document' => $this->media_nome ?: 'Documento',
            default    => 'Mensagem',
        };
    }

    /**
     * O que o provedor precisa saber para marcar esta mensagem como citada.
     *
     * Devolve NULL quando nao ha external_id — mensagem que ainda nao saiu, que falhou, ou
     * nota interna, que nunca existiu do lado de la. Citar algo que o WhatsApp nao conhece faz
     * ele recusar o envio inteiro, e a resposta sumiria por causa do enfeite. Sem o id a
     * mensagem vai sem citacao: o OnChat continua mostrando a ligacao no historico, e o
     * cliente recebe uma resposta normal em vez de nao receber nada.
     *
     * @return array{external_id: string, texto: ?string, minha: bool}|null
     */
    public function paraCitacao(): ?array
    {
        if (! $this->external_id) {
            return null;
        }

        return [
            'external_id' => (string) $this->external_id,
            'texto'       => $this->corpo ?: $this->legenda,
            'minha'       => ! $this->entrada(),
        ];
    }

    public function entrada(): bool
    {
        return $this->direcao === 'in';
    }

    public function temMidia(): bool
    {
        return $this->media_path !== null;
    }

    public function midiaUrl(): ?string
    {
        return $this->temMidia() ? route('media.show', $this) : null;
    }

    public function tamanhoLegivel(): ?string
    {
        if (! $this->media_tamanho) {
            return null;
        }

        $kb = $this->media_tamanho / 1024;

        return $kb < 1024 ? round($kb).' KB' : round($kb / 1024, 1).' MB';
    }
}
