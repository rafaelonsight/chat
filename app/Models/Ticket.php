<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Ticket extends Model
{
    use BelongsToTenant;

    public const RECEIVED = 'received';

    public const ANALYSING = 'analysing';

    public const PENDING_APPROVAL = 'pending_approval';

    public const ACCEPTED = 'accepted';

    public const IN_PROGRESS = 'in_progress';

    public const PENDING_VALIDATION = 'pending_validation';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::RECEIVED => 'Demanda recebida',
        self::ANALYSING => 'Em análise / solicitação de informação',
        self::PENDING_APPROVAL => 'Pendente de aprovação',
        self::ACCEPTED => 'Demanda aceita',
        self::IN_PROGRESS => 'Em execução',
        self::PENDING_VALIDATION => 'Pendente de validação',
        self::COMPLETED => 'Concluída',
        self::CANCELLED => 'Cancelada',
    ];

    public const CATEGORIES = [
        'support' => 'Suporte',
        'commercial' => 'Comercial',
        'implementation' => 'Implantação',
        'training' => 'Treinamento',
        'presentation' => 'Apresentação',
        'other' => 'Outro',
    ];

    public const PRIORITIES = [
        'low' => 'Baixa',
        'normal' => 'Normal',
        'high' => 'Alta',
        'critical' => 'Crítica',
    ];

    protected $fillable = [
        'tenant_id', 'protocol', 'client_account_id', 'client_project_id', 'requester_id',
        'responsible_id', 'supervisor_id', 'created_by', 'title', 'description', 'category',
        'status', 'priority', 'origin', 'first_response_due_at', 'resolution_due_at',
        'next_update_at', 'deadlines_paused_at', 'deadline_change_reason', 'accepted_at',
        'completed_at', 'cancelled_at', 'cancellation_reason', 'archived_at', 'hidden_at', 'hidden_reason',
    ];

    protected $casts = [
        'first_response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'next_update_at' => 'datetime',
        'deadlines_paused_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'archived_at' => 'datetime',
        'hidden_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::RECEIVED,
        'category' => 'support',
        'priority' => 'normal',
        'origin' => 'internal',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $ticket): void {
            $ticket->tenant_id ??= TenantContext::get();
            $client = ClientAccount::query()->find($ticket->client_account_id);

            if (! $client || $client->tenant_id !== $ticket->tenant_id) {
                throw new \DomainException('O cliente da demanda precisa pertencer à mesma conta.');
            }

            if ($ticket->client_project_id) {
                $project = ClientProject::query()->find($ticket->client_project_id);

                if (! $project || $project->client_account_id !== $ticket->client_account_id) {
                    throw new \DomainException('O projeto precisa pertencer ao cliente da demanda.');
                }
            }

            if ($ticket->requester_id) {
                $requester = ClientRequester::query()->find($ticket->requester_id);

                if (! $requester || $requester->client_account_id !== $ticket->client_account_id) {
                    throw new \DomainException('O solicitante precisa pertencer ao cliente da demanda.');
                }
            }

            foreach (array_filter([$ticket->responsible_id, $ticket->supervisor_id]) as $userId) {
                if (! User::query()->whereKey($userId)->exists()) {
                    throw new \DomainException('Responsável e supervisor precisam pertencer à mesma conta.');
                }
            }
        });

        static::creating(function (self $ticket): void {
            $ticket->protocol ??= self::nextProtocol();
            $ticket->status = self::RECEIVED;
            $ticket->created_by ??= auth()->id();
        });

        static::created(function (self $ticket): void {
            $ticket->recordEvent('created', null, $ticket->getAttributes());
            \App\Support\AvisoDeDemanda::nova($ticket);
        });

        static::updating(function (self $ticket): void {
            if ($ticket->isDirty('status')) {
                match ($ticket->status) {
                    self::ACCEPTED => $ticket->accepted_at ??= now(),
                    self::COMPLETED => $ticket->completed_at ??= now(),
                    self::CANCELLED => $ticket->cancelled_at ??= now(),
                    default => null,
                };
            }
        });

        static::updated(function (self $ticket): void {
            $changes = $ticket->getChanges();
            unset($changes['updated_at']);

            if ($changes !== []) {
                $old = array_intersect_key($ticket->getOriginal(), $changes);
                $ticket->recordEvent('updated', $old, $changes);
            }

            if (array_key_exists('status', $changes) && in_array($ticket->status, [
                self::RECEIVED, self::ANALYSING, self::PENDING_APPROVAL, self::ACCEPTED,
                self::PENDING_VALIDATION, self::COMPLETED, self::CANCELLED,
            ], true)) {
                try {
                    app(\App\Services\Demandas\WhatsappTicketNotifier::class)->send(
                        $ticket,
                        'O status da demanda foi atualizado para '.(self::STATUSES[$ticket->status] ?? $ticket->status).'.',
                        'status',
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        static::deleting(function (): never {
            throw new \DomainException('Tickets não podem ser excluídos. Cancele ou arquive a demanda.');
        });
    }

    public static function nextProtocol(): string
    {
        $row = DB::selectOne(
            'UPDATE ticket_counters SET next_number = next_number + 1, updated_at = ? WHERE id = 1 RETURNING next_number - 1 AS number',
            [now()]
        );

        if (! $row) {
            throw new \RuntimeException('Contador global de tickets não foi inicializado.');
        }

        return 'VRT-'.now()->format('Y').'-'.str_pad((string) $row->number, 6, '0', STR_PAD_LEFT);
    }

    public function recordEvent(string $event, ?array $old, ?array $new): void
    {
        TicketEvent::withoutEvents(fn () => TicketEvent::create([
            'tenant_id' => $this->tenant_id,
            'ticket_id' => $this->id,
            'actor_id' => auth()->id(),
            'event' => $event,
            'old_values' => $old,
            'new_values' => $new,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
        ]));
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at')->whereNull('archived_at');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(ClientRequester::class, 'requester_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_assignee')
            ->withPivot('assigned_by')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->latest();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(TicketTask::class)->orderBy('status')->orderBy('due_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class)->latest('created_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ticket_tag')
            ->withPivot(['applied_by', 'created_at']);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(TicketMeeting::class)->latest('starts_at');
    }
}
