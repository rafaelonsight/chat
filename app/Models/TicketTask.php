<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketTask extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'ticket_id', 'responsible_id', 'created_by', 'title', 'description',
        'status', 'priority', 'due_at', 'completed_at',
    ];

    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime'];

    protected $attributes = ['status' => 'pending', 'priority' => 'normal'];

    protected static function booted(): void
    {
        static::creating(fn (self $task) => $task->created_by ??= auth()->id());
        static::saving(function (self $task): void {
            if ($task->status === 'completed') {
                $task->completed_at ??= now();
            } else {
                $task->completed_at = null;
            }
        });
        static::created(fn (self $task) => $task->ticket->recordEvent('task_created', null, ['task_id' => $task->id]));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(TicketTaskChecklistItem::class)->orderBy('position');
    }
}
