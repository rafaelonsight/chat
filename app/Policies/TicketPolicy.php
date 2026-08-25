<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->tenant_id === $ticket->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $user->tenant_id === $ticket->tenant_id;
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
