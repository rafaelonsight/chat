<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMeeting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'ticket_id', 'appointment_id', 'meeting_id', 'requested_by', 'category', 'status', 'starts_at', 'duration_minutes', 'participant_user_ids', 'notes'];
    protected $casts = ['starts_at' => 'datetime', 'participant_user_ids' => 'array'];

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function appointment(): BelongsTo { return $this->belongsTo(Appointment::class); }
    public function meeting(): BelongsTo { return $this->belongsTo(Meeting::class); }
}
