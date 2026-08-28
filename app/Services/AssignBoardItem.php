<?php

namespace App\Services;

use App\Models\{BoardItem, Funnel, User};
use App\Support\{AvisoDeItem, BoardAccess};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignBoardItem
{
    public function execute(User $actor, int $itemId, array $userIds): BoardItem
    {
        abort_unless(config('boards.foundation_enabled'), 503);
        abort_unless($actor->tenant_id, 403);
        foreach ($userIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw ValidationException::withMessages(['responsaveis' => 'Responsável inválido.']);
            }
        }
        $ids = array_values(array_unique($userIds));
        if (count($ids) > 50) {
            throw ValidationException::withMessages(['responsaveis' => 'Selecione até 50 responsáveis.']);
        }

        return DB::transaction(function () use ($actor, $itemId, $ids) {
            $hint = BoardItem::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)->findOrFail($itemId);
            $board = Funnel::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)
                ->lockForUpdate()->findOrFail($hint->funnel_id);
            $item = BoardItem::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)
                ->where('funnel_id',$board->id)->lockForUpdate()->findOrFail($itemId);
            $access = app(BoardAccess::class);
            abort_if($board->arquivado_em,403);
            abort_unless($access->canEdit($actor, $board), 403);
            $users = User::withoutGlobalScopes()->where('tenant_id', $actor->tenant_id)->whereIn('id', $ids)->get();
            if ($users->count() !== count($ids) || $users->contains(fn ($user) => ! $access->canEdit($user, $board))) {
                throw ValidationException::withMessages(['responsaveis' => 'Escolha colaboradores com acesso de edição ao painel.']);
            }
            $query = DB::table('board_item_assignees')->where('tenant_id', $actor->tenant_id)->where('board_item_id', $item->id);
            $before = $query->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            // Legacy assignments made since migration remain part of the compatibility view.
            if ($item->responsavel_id) {
                $before[] = (int) $item->responsavel_id;
            }
            $before = array_values(array_unique($before));
            $added = array_values(array_diff($ids, $before));
            $removed = array_values(array_diff($before, $ids));
            $query->whereNotIn('user_id', $ids)->delete();
            foreach ($ids as $id) {
                DB::table('board_item_assignees')->insertOrIgnore([
                    'tenant_id' => $actor->tenant_id, 'board_item_id' => $item->id, 'user_id' => $id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Keep the primary assignee stable when input order changes.
            $primary = in_array((int) $item->responsavel_id, $ids, true) ? $item->responsavel_id : ($ids[0] ?? null);
            $item->forceFill(['responsavel_id' => $primary])->saveQuietly();
            if ($added !== [] || $removed !== []) {
                $item->updates()->create([
                    'tenant_id' => $actor->tenant_id, 'user_id' => $actor->id,
                    'tipo' => 'responsaveis', 'corpo' => 'Responsáveis atualizados.',
                ]);
                AvisoDeItem::enviar($item, array_values(array_diff($added, [$actor->id])), 'Nova tarefa atribuída a você');
            }

            return $item;
        });
    }
}
