<?php

namespace ErnestDefoe\Corkboard\Api;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Column;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * The boards list (`GET /api/corkboard-boards`): the tabs and search, the pinned row, and what a
 * tile shows (the team's pictures, "due this week"), a fixed number of queries for a page of tiles.
 */
class BoardList
{
    public const PAGE = 30;
    public const TABS = ['mine', 'all', 'completed', 'archived', 'templates'];

    /** @var WeakMap<User, Collection<array-key, int>> the viewer's pins (board id => position), once per request */
    private WeakMap $pins;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Presenter $presenter,
    ) {
        $this->pins = new WeakMap();
    }

    /** @param array<string, mixed> $filters */
    public static function tab(array $filters, User $actor): string
    {
        $tab = $filters['tab'] ?? '';

        return in_array($tab, self::TABS, true) ? $tab : ($actor->isGuest() ? 'all' : 'mine');
    }

    /**
     * A tab, a project's boards (the Projects page shows them) and a word in the title, on boards
     * the viewer may see (the resource's scope), newest activity first (templates by name).
     *
     * @param Builder<Board> $query
     * @param array<string, mixed> $filters
     */
    public function filter(Builder $query, User $actor, array $filters): void
    {
        $tab = self::tab($filters, $actor);
        $query->select('corkboard_boards.*');
        match ($tab) {
            'completed' => $query->where('corkboard_boards.status', 'completed'),
            'archived' => $query->where('corkboard_boards.status', 'archived'),
            // Template boards to start from: only for people who may (corkboard.useTemplates).
            'templates' => $query->where('corkboard_boards.is_template', true)->where('corkboard_boards.status', 'active')
                ->when(! $actor->hasPermission('corkboard.useTemplates'), fn (Builder $q) => $q->whereIn('corkboard_boards.id', [])),
            default => $query->where('corkboard_boards.status', 'active'),
        };
        if ($tab === 'mine') {
            $query->whereIn('corkboard_boards.id', $actor->isGuest() ? [] : self::onTeam($actor));
        }
        if (! empty($filters['project']) && is_scalar($filters['project'])) {
            $query->where('corkboard_boards.project_id', (int) $filters['project']);
        }
        $text = self::search($filters);
        if ($text !== '') {
            // A word anywhere in the title ("redesign" finds "Website redesign"). It runs on the
            // visible, tab-filtered boards only and stops at one page, so it never scans posts-sized tables.
            $query->whereLike('corkboard_boards.title', '%'.addcslashes($text, '%_\\').'%');
        }
        if ($tab === 'templates') {
            $query->orderBy('corkboard_boards.title');
        } else {
            $query->orderByDesc('corkboard_boards.last_activity_at')->orderByDesc('corkboard_boards.id');
        }
    }

    /** @param array<string, mixed> $filters */
    public static function search(array $filters): string
    {
        return is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
    }

    /**
     * Pinned boards the viewer can still see, in their order, for the row on top (the first page
     * of Mine and All, without a search).
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function pinned(User $actor, array $filters, int $offset): array
    {
        $pins = $this->pins($actor);
        if ($offset > 0 || $pins->isEmpty() || ! in_array(self::tab($filters, $actor), ['mine', 'all'], true) || self::search($filters) !== '') {
            return [];
        }
        $boards = Board::whereVisibleTo($actor)->whereIn('corkboard_boards.id', $pins->keys())->where('corkboard_boards.status', 'active')->get()
            ->sortBy(fn (Board $b) => $pins[$b->id])->values();

        return array_values($this->tiles($boards, $actor));
    }

    /**
     * What each tile shows, keyed by board id: team pictures and "due this week" for every tile in
     * one query each, and the people's names in one more. Template tiles also list their columns
     * (the template picker shows them), in one more.
     *
     * @param Collection<int, Board> $boards
     * @return array<int, array<string, mixed>>
     */
    public function tiles(Collection $boards, User $actor, bool $withColumns = false): array
    {
        if ($boards->isEmpty()) {
            return [];
        }
        $this->access->prime($boards, $actor);
        $pins = $this->pins($actor);
        $ids = $boards->pluck('id')->all();
        $members = $this->db->table('corkboard_members')->whereIn('board_id', $ids)->whereNotNull('user_id')->orderBy('id')->get(['board_id', 'user_id'])->groupBy('board_id');
        // Cards due within a week, per board, in one grouped count.
        $due = $this->db->table('corkboard_cards')->whereIn('board_id', $ids)->whereNull('archived_at')->whereNull('completed_at')
            ->whereNotNull('due_at')->where('due_at', '<=', Carbon::now()->addDays(7))
            ->groupBy('board_id')->selectRaw('board_id, count(*) as n')->pluck('n', 'board_id');

        $people = [];
        foreach ($boards as $board) {
            $ids = array_values(array_unique(array_merge(array_filter([$board->owner_id]), ($members[$board->id] ?? collect())->pluck('user_id')->map(fn ($id) => (int) $id)->all())));
            $people[$board->id] = array_slice($ids, 0, 5);
        }
        $users = $this->presenter->users(array_merge(...array_values($people)));
        $columns = $withColumns ? Column::query()->whereIn('board_id', $boards->pluck('id'))->orderBy('position')->orderBy('id')->get(['board_id', 'title'])->groupBy('board_id') : collect();
        $users = is_array($users) ? $users : [];

        $tiles = [];
        foreach ($boards as $board) {
            $tiles[$board->id] = $this->presenter->boardSummary($board) + [
                'description' => $board->description,
                'role' => $this->access->role($board, $actor),
                // The people themselves (at most five a tile), not ids: the list has no users map.
                'people' => array_values(array_filter(array_map(fn (int $id) => $users[$id] ?? null, $people[$board->id]))),
                'teamSize' => count($members[$board->id] ?? []) + ($board->owner_id ? 1 : 0),
                'dueSoon' => (int) ($due[$board->id] ?? 0),
                'pinned' => isset($pins[$board->id]),
                'columns' => $withColumns ? ($columns[$board->id] ?? collect())->pluck('title')->all() : null,
            ];
        }

        return $tiles;
    }

    /** @return Collection<array-key, int> */
    private function pins(User $actor): Collection
    {
        if ($actor->isGuest()) {
            return collect();
        }

        return $this->pins[$actor] ??= $this->db->table('corkboard_pins')->where('user_id', $actor->id)->pluck('position', 'board_id')->map(fn ($p) => (int) $p);
    }

    /**
     * Boards the viewer owns or is on the team of (by name, or through a group other than Members),
     * as a subquery of board ids: the Mine tab and the Due soon list (CardList) share it.
     *
     * @return \Closure(QueryBuilder): QueryBuilder
     */
    public static function onTeam(User $actor): \Closure
    {
        $groups = array_values(array_diff(Access::groupIds($actor), [Group::GUEST_ID, Group::MEMBER_ID]));

        return fn (QueryBuilder $q) => $q->select('id')->from('corkboard_boards')->where('owner_id', $actor->id)
            ->orWhereIn('id', fn (QueryBuilder $m) => $m->select('board_id')->from('corkboard_members')
                ->where('user_id', $actor->id)
                ->when($groups, fn (QueryBuilder $g) => $g->orWhereIn('group_id', $groups)));
    }
}
