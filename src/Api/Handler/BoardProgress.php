<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Api\Find;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/corkboard/progress/{id}   a board's columns, counts and next due cards (the Board progress widget) */
class BoardProgress extends Handler
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $board = Find::board($actor, $this->id($request));
        // Cards per column in one grouped count (the builder has no grouped count of its own).
        $counts = $this->db->table('corkboard_cards')->where('board_id', $board->id)->whereNull('archived_at')
            ->groupBy('column_id')->selectRaw('column_id, count(*) as n')->pluck('n', 'column_id');
        $columns = $this->db->table('corkboard_columns')->where('board_id', $board->id)->orderBy('position')->orderBy('id')->get(['id', 'title', 'is_done']);
        $next = $this->db->table('corkboard_cards')->where('board_id', $board->id)->whereNull('archived_at')->whereNull('completed_at')
            ->whereNotNull('due_at')->orderBy('due_at')->limit(3)->get(['id', 'title', 'due_at', 'due_all_day']);

        return [
            'board' => $this->presenter->boardSummary($board),
            'columns' => $columns->map(fn ($c) => ['id' => (int) $c->id, 'title' => $c->title, 'isDone' => (bool) $c->is_done, 'count' => (int) ($counts[$c->id] ?? 0)])->values()->all(),
            'next' => $next->map(fn ($c) => ['id' => (int) $c->id, 'title' => $c->title, 'dueAt' => Presenter::iso($c->due_at), 'dueAllDay' => (bool) $c->due_all_day])->values()->all(),
        ];
    }
}
