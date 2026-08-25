<?php

namespace App\Services\Demandas;

use App\Models\Appointment;
use App\Models\Ticket;
use App\Models\TicketMeeting;
use App\Models\User;
use App\Services\Video\Chamada;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DemandMeetingScheduler
{
    public function participants(Ticket $ticket, string $category): Collection
    {
        $names = match ($category) {
            'support' => ['celso'],
            'commercial' => ['rafael'],
            'implementation', 'training', 'presentation' => ['celso', 'rafael'],
            default => ['celso'],
        };

        $users = User::withoutGlobalScope('tenant')->where('tenant_id', $ticket->tenant_id)
            ->where(function ($q) use ($names) { foreach ($names as $name) { $q->orWhereRaw('lower(name) like ?', ["%{$name}%"]); } })
            ->get()->unique('id')->values();

        if ($users->count() < count($names) && $category === 'support') {
            $fallback = User::withoutGlobalScope('tenant')->where('tenant_id', $ticket->tenant_id)->whereRaw('lower(name) like ?', ['%rafael%'])->first();
            if ($fallback) $users->push($fallback);
        }

        if ($users->isEmpty()) throw new \DomainException('Nenhum responsável da categoria foi localizado.');
        return $users;
    }

    public function available(Ticket $ticket, string $category, Carbon $start, int $duration = 30): bool
    {
        $end = $start->copy()->addMinutes($duration);
        return $this->participants($ticket, $category)->every(fn (User $user) => ! Appointment::withoutGlobalScope('tenant')
            ->where('tenant_id', $ticket->tenant_id)->where('user_id', $user->id)
            ->whereNull('concluido_em')->where('comeca_em', '<', $end)
            ->whereRaw("comeca_em + (coalesce(duracao_min, 30) || ' minutes')::interval > ?", [$start])->exists());
    }

    public function schedule(Ticket $ticket, string $category, Carbon $start, int $duration = 30, ?int $requesterId = null): TicketMeeting
    {
        if (! $this->available($ticket, $category, $start, $duration)) throw new \DomainException('Esse horário acabou de ficar indisponível. Escolha outro.');
        $users = $this->participants($ticket, $category);
        $primary = null;
        foreach ($users as $user) {
            $appointment = Appointment::create([
                'tenant_id' => $ticket->tenant_id, 'user_id' => $user->id, 'criado_por' => auth()->id(),
                'contact_id' => $ticket->client->contact_id, 'tipo' => Appointment::COMPROMISSO,
                'titulo' => "{$ticket->protocol} — {$ticket->title}", 'descricao' => 'Reunião vinculada à demanda.',
                'comeca_em' => $start, 'duracao_min' => $duration,
            ]);
            $primary ??= $appointment;
        }
        $meeting = app(Chamada::class)->paraCompromisso($primary);

        return TicketMeeting::create([
            'tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id,
            'appointment_id' => $primary->id, 'meeting_id' => $meeting->id, 'requested_by' => $requesterId,
            'category' => $category, 'status' => 'confirmed', 'starts_at' => $start,
            'duration_minutes' => $duration, 'participant_user_ids' => $users->pluck('id')->all(),
        ]);
    }
}
