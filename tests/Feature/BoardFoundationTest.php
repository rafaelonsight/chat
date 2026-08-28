<?php

use App\Models\{BoardItem, Funnel, Tenant, User};
use App\Services\AssignBoardItem;
use App\Support\{BoardAccess, TenantContext};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['boards.foundation_enabled' => true]);
    $this->tenant = Tenant::create(['nome' => 'Foundation', 'slug' => 'foundation']);
    TenantContext::set($this->tenant->id);
    $this->actor = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'admin' => true]);
    $this->colleague = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'email' => 'colleague@example.test', 'password' => 'password']);
    $this->actingAs($this->actor);
    $this->board = Funnel::criarCom('Foundation');
    $this->item = BoardItem::create(['tenant_id' => $this->tenant->id, 'funnel_id' => $this->board->id,
        'funnel_stage_id' => $this->board->stages->first()->id, 'titulo' => 'Foundation']);
    $this->service = app(AssignBoardItem::class);
    $this->access = app(BoardAccess::class);
});
afterEach(fn () => TenantContext::forget());

it('requires explicit feature activation', function () {
    config(['boards.foundation_enabled' => false]);
    expect(fn () => $this->service->execute($this->actor, $this->item->id, []))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('board_item_assignees')->count())->toBe(0);
});

it('assigns multiple users and is idempotent including repeated ids', function () {
    $ids = [$this->colleague->id, $this->actor->id, $this->colleague->id];
    $this->service->execute($this->actor, $this->item->id, $ids);
    $this->service->execute($this->actor, $this->item->id, array_reverse($ids));
    expect(DB::table('board_item_assignees')->count())->toBe(2)
        ->and($this->item->refresh()->responsavel_id)->toBe($this->colleague->id)
        ->and($this->item->updates()->where('tipo', 'responsaveis')->count())->toBe(1)
        ->and($this->colleague->notifications()->count())->toBe(1)
        ->and($this->actor->notifications()->count())->toBe(0);
});

it('removes assignees and clears the legacy primary atomically', function () {
    $this->service->execute($this->actor, $this->item->id, [$this->colleague->id]);
    $this->service->execute($this->actor, $this->item->id, []);
    expect(DB::table('board_item_assignees')->count())->toBe(0)
        ->and($this->item->refresh()->responsavel_id)->toBeNull();
});

it('rejects foreign users without partial writes', function () {
    $other = Tenant::create(['nome' => 'Other', 'slug' => 'other']);
    $foreign = User::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'email' => 'foreign@example.test', 'password' => 'password', 'admin' => true]);
    expect(fn () => $this->service->execute($this->actor, $this->item->id, [$this->colleague->id, $foreign->id]))->toThrow(ValidationException::class);
    expect(DB::table('board_item_assignees')->count())->toBe(0)
        ->and($this->access->canView($foreign, $this->board))->toBeFalse();
    expect(fn () => $this->service->execute($foreign, $this->item->id, []))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('rejects malformed ids', function ($ids) {
    expect(fn () => $this->service->execute($this->actor, $this->item->id, $ids))->toThrow(ValidationException::class);
})->with([[['1']], [[-1]], [[null]], [[true]], [[1.5]], [[99999999]]]);

it('keeps public boards compatible and private boards closed', function () {
    expect($this->access->canEdit($this->colleague, $this->board))->toBeTrue()
        ->and($this->access->canManage($this->colleague, $this->board))->toBeFalse();
    $this->board->forceFill(['restrito' => true])->save();
    expect($this->access->canView($this->colleague, $this->board))->toBeFalse()
        ->and($this->access->canManage($this->actor, $this->board))->toBeTrue();
    expect(fn () => $this->service->execute($this->actor, $this->item->id, [$this->colleague->id]))->toThrow(ValidationException::class);
});

it('enforces roles', function ($role, $view, $edit, $manage) {
    $this->board->forceFill(['restrito' => true])->save();
    DB::table('board_members')->insert(['tenant_id' => $this->tenant->id, 'funnel_id' => $this->board->id, 'user_id' => $this->colleague->id, 'papel' => $role]);
    expect($this->access->canView($this->colleague, $this->board))->toBe($view)
        ->and($this->access->canEdit($this->colleague, $this->board))->toBe($edit)
        ->and($this->access->canManage($this->colleague, $this->board))->toBe($manage);
    if (! $edit) {
        expect(fn () => $this->service->execute($this->colleague, $this->item->id, []))
            ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
})->with([['leitor', true, false, false], ['editor', true, true, false], ['gestor', true, true, true], ['invalid', false, false, false]]);

it('does not send another notification for an existing legacy assignee', function () {
    $this->item->update(['responsavel_id' => $this->colleague->id]);
    $before = $this->colleague->notifications()->count();
    $this->service->execute($this->actor, $this->item->id, [$this->colleague->id]);
    expect($this->colleague->notifications()->count())->toBe($before)
        ->and(DB::table('board_item_assignees')->count())->toBe(1);
});

it('backfills an existing assignee without emitting model events', function () {
    $this->item->update(['responsavel_id' => $this->colleague->id]);
    $migration = require database_path('migrations/2026_08_28_120000_board_foundation.php');
    $before = $this->colleague->notifications()->count();
    $migration->down();
    $migration->up();
    expect(DB::table('board_item_assignees')->where('board_item_id', $this->item->id)->value('user_id'))->toBe($this->colleague->id)
        ->and($this->colleague->notifications()->count())->toBe($before)
        ->and($this->item->refresh()->responsavel_id)->toBe($this->colleague->id);
});
