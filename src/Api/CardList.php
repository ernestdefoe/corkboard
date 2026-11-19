<?php

namespace ErnestDefoe\Corkboard\Api;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Column;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The card lists (`GET /api/corkboard-cards`), on cards the viewer may see (the resource's scope):
 *
 * filter[mine]=1 (&filter[owned]=1 &filter[done]=1)   cards they're on (or started), due first
 * filter[dueWithin]=days (&filter[board]=)            due soon on boards they're on (or one board)
 * filter[column]= (&filter[after]=position)           a big column's next cards
 * filter[board]=&filter[archived]=1                   a board's archived cards, newest first
 * filter[board]= / filter[column]= alone              open cards by board and position
 *
 * and what each listed card shows (as the board draws it, plus where it is), for a page at once.
 */
class CardList
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Presenter $presenter,
    ) {
    }

    /**
     * @param Builder<Card> $query
     * @param array<string, mixed> $filters
     */
    public function filter(Builder $query, User $actor, array $filters): void
    {
        $int = fn (string $key) => isset($filters[$key]) && is_numeric($filters[$key]) ? (int) $filters[$key] : null;
        $board = $int('board');
        if ($board !== null) {
            $query->where('corkboard_cards.board_id', $board);
        }
        if (! empty($filters['archived'])) {
            $query->whereNotNull('corkboard_cards.archived_at')->orderByDesc('corkboard_cards.archived_at');

            return;
        }
        $query->whereNull('corkboard_cards.archived_at');

        if (! empty($filters['mine'])) {
            $this->mine($query, $actor, ! empty($filters['owned']), ! empty($filters['done']));
        } elseif ($int('dueWithin') !== null) {
            $this->dueSoon($query, $actor, max(1, min(30, (int) $int('dueWithin'))), $board !== null);
        } elseif ($int('column') !== null) {
            $query->where('corkboard_cards.column_id', $int('column'))
                ->when($int('after') !== null, fn (Builder $q) => $q->where('corkboard_cards.position', '>', $int('after')))
                ->orderBy('corkboard_cards.position');
        } else {
            $query->orderBy('corkboard_cards.board_id')->orderBy('corkboard_cards.position');
        }
    }

    /**
     * Cards they're on (or started, with owned), open unless done: due first (soonest first), then
     * by priority, newest first. The CASEs are the portable way to sort nulls last and rank
     * priorities on MySQL, PostgreSQL and SQLite alike.
     *
     * @param Builder<Card> $query
     */
    private function mine(Builder $query, User $actor, bool $owned, bool $done): void
    {
        if ($actor->isGuest()) {
            $query->whereIn('corkboard_cards.id', []);

            return;
        }
        $query->whereIn('corkboard_cards.board_id', self::activeBoards())
            ->where(function (Builder $w) use ($actor, $owned) {
                $w->whereIn('corkboard_cards.id', fn ($a) => $a->select('card_id')->from('corkboard_assignees')->where('user_id', $actor->id));
                if ($owned) {
                    $w->orWhere('corkboard_cards.user_id', $actor->id);
                }
            });
        if (! $done) {
            $query->whereNull('corkboard_cards.completed_at');
        }
        $grammar = $this->db->getQueryGrammar();
        $priority = $grammar->wrap('corkboard_cards.priority');
        $query->orderByRaw('CASE WHEN '.$grammar->wrap('corkboard_cards.due_at').' IS NULL THEN 1 ELSE 0 END')
            ->orderBy('corkboard_cards.due_at')
            ->orderByRaw("CASE {$priority} WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByDesc('corkboard_cards.id');
    }

    /**
     * Open cards due within so many days (overdue ones too), soonest first: on one board, or on
     * the boards they're on (BoardList::onTeam). Guests only ever ask for one board.
     *
     * @param Builder<Card> $query
     */
    private function dueSoon(Builder $query, User $actor, int $days, bool $oneBoard): void
    {
        if (! $oneBoard && $actor->isGuest()) {
            $query->whereIn('corkboard_cards.id', []);
        } elseif (! $oneBoard) {
            $query->whereIn('corkboard_cards.board_id', BoardList::onTeam($actor));
        }
        $query->whereIn('corkboard_cards.board_id', self::activeBoards())
            ->whereNull('corkboard_cards.completed_at')
            ->whereNotNull('corkboard_cards.due_at')
            ->where('corkboard_cards.due_at', '<=', Carbon::now()->addDays($days))
            ->orderBy('corkboard_cards.due_at');
    }

    /** @return \Closure(\Illuminate\Database\Query\Builder): void boards that are open (not completed, archived or deleted) */
    private static function activeBoards(): \Closure
    {
        return fn ($b) => $b->select('id')->from('corkboard_boards')->where('status', 'active')->whereNull('deleted_at');
    }

    /**
     * What each card on a listed page shows, keyed by card id: as the board draws it (with its
     * labels and people), its column's title and its people themselves. Four queries for any page.
     *
     * @param Collection<int, Card> $cards
     * @return array<int, array<string, mixed>>
     */
    public function rows(Collection $cards): array
    {
        if ($cards->isEmpty()) {
            return [];
        }
        [$slim, $userIds] = $this->presenter->slim($cards->map(fn (Card $card) => (object) $card->getAttributes()));
        $users = $this->presenter->users($userIds);
        $users = is_array($users) ? $users : [];
        $columns = Column::query()->whereIn('id', $cards->pluck('column_id')->unique())->pluck('title', 'id');

        $archived = $cards->mapWithKeys(fn (Card $card) => [$card->id => $card->archived_at?->toIso8601String()]);
        $rows = [];
        foreach ($slim as $row) {
            $rows[$row['id']] = $row + [
                'archivedAt' => $archived[$row['id']] ?? null,
                'columnTitle' => $columns[$row['columnId']] ?? null,
                'people' => array_values(array_filter(array_map(fn (int $id) => $users[$id] ?? null, $row['assigneeIds']))),
            ];
        }

        return $rows;
    }
}
