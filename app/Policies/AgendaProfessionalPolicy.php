<?php

namespace App\Policies;

use App\Models\AgendaProfessional;
use App\Models\User;

class AgendaProfessionalPolicy
{
    public function viewAny(User $user): bool { return (bool) $user->tenant_id && !$user->isAgendaProfessional(); }
    public function view(User $user, AgendaProfessional $record): bool { return $this->viewAny($user) && $user->tenant_id === $record->tenant_id; }
    public function create(User $user): bool { return (bool) $user->tenant_id && ($user->admin || $user->perfil === 'admin'); }
    public function update(User $user, AgendaProfessional $record): bool { return $this->create($user) && $this->view($user,$record); }
    public function delete(User $user, AgendaProfessional $record): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
}
