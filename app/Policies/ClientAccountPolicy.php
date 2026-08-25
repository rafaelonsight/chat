<?php

namespace App\Policies;

use App\Models\ClientAccount;
use App\Models\User;

class ClientAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClientAccount $client): bool
    {
        return $user->tenant_id === $client->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->admin || $user->operador;
    }

    public function update(User $user, ClientAccount $client): bool
    {
        return ($user->admin || $user->operador) && $user->tenant_id === $client->tenant_id;
    }

    public function delete(User $user, ClientAccount $client): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
