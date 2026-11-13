<?php

namespace ErnestDefoe\Corkboard\Api;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /corkboard/my-cards?limit=&owned=1&done=1   the viewer's cards (the My cards widget and page)
 * GET /corkboard/due-soon?days=&board=&limit=     what's due soon on the viewer's boards (or one board)
 * GET /corkboard/progress/{id}                    a board's columns, counts and next due cards
 *
 * Guests get empty lists (the widgets then draw nothing). Each is a fixed number of queries.
 */
class WidgetsController extends Controller
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Presenter $presenter,
    ) {
    }

    /** @return array<string, mixed> */
    protected function mine(ServerRequestInterface $request, User $actor): array
    {
        if ($actor->isGuest()) {
            return ['cards' => [], 'total' => 0];
        }
        $q = $request->getQueryParams();
        $limit = max(1, min(100, (int) ($q['limit'] ?? 6)));

        $query = $this->listing($actor)
            ->where(function (Builder $w) use ($actor, $q) {
                $w->whereIn('corkboard_cards.id', fn ($a) => $a->select('card_id')->from('corkboard_assignees')->where('user_id', $actor->id));
                if (! empty($q['owned'])) {
                    $w->orWhere('corkboard_cards.user_id', $actor->id);
                }
            });
        if (empty($q['done'])) {
            $query->whereNull('corkboard_cards.completed_at');
        }
        $total = (clone $query)->count();
        // Due first (soonest first), then the rest by priority, newest first.
        $grammar = $this->db->getQueryGrammar();
        $rows = $query->orderByRaw('CASE WHEN '.$grammar->wrap('corkboard_cards.due_at').' IS NULL THEN 1 ELSE 0 END')
            ->orderBy('corkboard_cards.due_at')
            ->orderByRaw($this->priorityOrder())
            ->orderByDesc('corkboard_cards.id')
            ->limit($limit)->get();

        return ['cards' => $this->rows($rows), 'total' => $total];
    }

    /** @return array<string, mixed> */
    protected function dueSoon(ServerRequestInterface $request, User $actor): array
    {
        $q = $request->getQueryParams();
        $days = max(1, min(30, (int) ($q['days'] ?? 3)));
        $limit = max(1, min(30, (int) ($q['limit'] ?? 5)));
        $query = $this->listing($actor)
            ->whereNull('corkboard_cards.completed_at')
            ->whereNotNull('corkboard_cards.due_at')
            ->where('corkboard_cards.due_at', '<=', Carbon::now()->addDays($days));

        if (! empty($q['board'])) {
            $query->where('corkboard_cards.board_id', (int) $q['board']);
        } elseif ($actor->isGuest()) {
            return ['cards' => [], 'users' => (object) []];
        } else {
            // Boards they're on: owner, by name, or through a group (not just "everyone signed in").
            $groups = array_values(array_diff(\ErnestDefoe\Corkboard\Access::groupIds($actor), [\Flarum\Group\Group::GUEST_ID, \Flarum\Group\Group::MEMBER_ID]));
            $query->where(fn (Builder $w) => $w->where('corkboard_boards.owner_id', $actor->id)
                ->orWhereIn('corkboard_cards.board_id', fn ($m) => $m->select('board_id')->from('corkboard_members')
                    ->where('user_id', $actor->id)->when($groups, fn ($g) => $g->orWhereIn('group_id', $groups))));
        }
        $rows = $query->orderBy('corkboard_cards.due_at')->limit($limit)->get();
        $cards = $this->rows($rows);

        // Who's on each, for "Ana, Marcus".
        $ids = array_column($cards, 'id');
        $people = $ids ? $this->db->table('corkboard_assignees')->whereIn('card_id', $ids)->orderBy('created_at')->get(['card_id', 'user_id']) : collect();
        foreach ($cards as &$card) {
            $card['assigneeIds'] = $people->where('card_id', $card['id'])->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return ['cards' => $cards, 'users' => $this->presenter->users($people->pluck('user_id')->all())];
    }

    /** @return array<string, mixed> */
    protected function progress(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);
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

    /** Open cards the viewer may see, with their board and column (one query). */
    private function listing(User $actor): Builder
    {
        return Card::whereVisibleTo($actor)
            ->join('corkboard_boards', 'corkboard_boards.id', '=', 'corkboard_cards.board_id')
            ->join('corkboard_columns', 'corkboard_columns.id', '=', 'corkboard_cards.column_id')
            ->whereNull('corkboard_boards.deleted_at')
            ->where('corkboard_boards.status', 'active')
            ->whereNull('corkboard_cards.archived_at')
            ->select([
                'corkboard_cards.id', 'corkboard_cards.title', 'corkboard_cards.priority', 'corkboard_cards.due_at', 'corkboard_cards.due_all_day',
                'corkboard_cards.completed_at', 'corkboard_cards.board_id', 'corkboard_boards.title as board_title', 'corkboard_boards.slug as board_slug',
                'corkboard_boards.color as board_color', 'corkboard_columns.title as column_title',
            ]);
    }

    private function priorityOrder(): string
    {
        $p = $this->db->getQueryGrammar()->wrap('corkboard_cards.priority');

        return "CASE {$p} WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";
    }

    /**
     * @param iterable<object> $rows
     * @return list<array<string, mixed>>
     */
    private function rows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r->id,
                'title' => $r->title,
                'priority' => $r->priority,
                'dueAt' => Presenter::iso($r->getAttributes()['due_at'] ?? null),
                'dueAllDay' => (bool) $r->due_all_day,
                'completedAt' => Presenter::iso($r->getAttributes()['completed_at'] ?? null),
                'boardId' => (int) $r->board_id,
                'board' => $r->board_title,
                'boardSlug' => $r->board_slug,
                'boardColor' => $r->board_color,
                'column' => $r->column_title,
            ];
        }

        return $out;
    }
}
