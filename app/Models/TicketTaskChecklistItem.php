<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTaskChecklistItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_task_id', 'text', 'done', 'position'];

    protected $casts = ['done' => 'boolean'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(TicketTask::class, 'ticket_task_id');
    }
}
