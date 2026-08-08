<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um recado escrito durante a chamada. */
class MeetingMessage extends Model
{
    use BelongsToTenant;

    /** Cabe endereco, numero de serie e um link. Acima disso e outra ferramenta. */
    public const LIMITE = 800;

    protected $fillable = ['tenant_id', 'meeting_id', 'user_id', 'nome', 'corpo'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function daEquipe(): bool
    {
        return $this->user_id !== null;
    }
}
