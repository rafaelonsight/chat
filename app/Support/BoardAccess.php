<?php

namespace App\Support;

use App\Models\{Funnel, User};
use Illuminate\Support\Facades\DB;

class BoardAccess
{
    public function role(User $user, Funnel $board): ?string
    {
        // An operator/admin must never bypass the tenant boundary.
        if (! $user->tenant_id || (int) $user->tenant_id !== (int) $board->tenant_id) {
            return null;
        }
        if ($user->veTudo()) {
            return 'gestor';
        }
        $role = DB::table('board_members')->where('tenant_id', $board->tenant_id)
            ->where('funnel_id', $board->id)->where('user_id', $user->id)->value('papel');
        if ($role !== null) {
            return in_array($role, ['gestor', 'editor', 'leitor'], true) ? $role : null;
        }

        return $board->restrito ? null : 'editor';
    }

    public function canView(User $user, Funnel $board): bool
    {
        return $this->role($user, $board) !== null;
    }

    public function canEdit(User $user, Funnel $board): bool
    {
        return in_array($this->role($user, $board), ['gestor', 'editor'], true);
    }

    public function canManage(User $user, Funnel $board): bool
    {
        return $this->role($user, $board) === 'gestor';
    }
}
