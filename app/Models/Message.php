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
        'tipo', 'corpo', 'external_id', 'status', 'erro', 'enviada_em',
        'media_path', 'media_mime', 'media_nome', 'media_tamanho', 'media_duracao', 'legenda',
    ];

    protected $casts = [
        'enviada_em'     => 'datetime',
        'media_tamanho'  => 'integer',
        'media_duracao'  => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
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
