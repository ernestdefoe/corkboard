<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Activity;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * `corkboard-activity`: a board's history, read only, newest first, 30 a page.
 *
 * GET /api/corkboard-activity?filter[board]=&filter[before]=   a board's history
 * GET /api/corkboard-activity?filter[card]=&filter[before]=    one card's
 *
 * Rows hold ids; meta.names has today's names for the ids on the page (people, groups, cards,
 * columns), so a renamed card or a deleted account reads right. A board or card the viewer can't
 * see is a 404, and the rows follow their board's visibility either way.
 *
 * @extends Resource<Activity>
 */
class ActivityResource extends Resource
{
    public const PAGE = 30;

    /** The board the listed page belongs to (set by its filter). */
    private ?Board $board = null;

    /** @var EloquentCollection<int, \Illuminate\Database\Eloquent\Model>|null the listed page, read once for its names and its rows */
    private ?EloquentCollection $page = null;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Presenter $presenter,
    ) {
    }

    public function type(): string
    {
        return 'corkboard-activity';
    }

    public function model(): string
    {
        return Activity::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereIn('corkboard_activity.board_id', Board::whereVisibleTo($context->getActor())->select('corkboard_boards.id'));
    }

    public function endpoints(): array
    {
        return [
            self::list(self::PAGE, function (Builder $query, array $filters, Context $context) {
                $actor = $context->getActor();
                $int = fn (string $key) => isset($filters[$key]) && is_numeric($filters[$key]) ? (int) $filters[$key] : null;
                if ($card = $int('card')) {
                    $found = Find::card($actor, $card);
                    $this->board = $found->board;
                    $query->where('corkboard_activity.card_id', $found->id);
                } elseif ($board = $int('board')) {
                    $this->board = Find::board($actor, $board);
                } else {
                    throw new ModelNotFoundException();
                }
                $query->where('corkboard_activity.board_id', $this->board->id);
                // Older than a line already shown: new history never shifts the next page.
                if ($before = $int('before')) {
                    $query->where('corkboard_activity.id', '<', $before);
                }
                $query->orderByDesc('corkboard_activity.id');
            })->meta([
                Schema\Arr::make('names')->get(fn (Context $context) => $this->names($context)),
            ]),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('type'),
            Schema\Integer::make('userId')->property('user_id')->nullable(),
            Schema\Integer::make('cardId')->property('card_id')->nullable(),
            Schema\Arr::make('data')->get(fn (Activity $row) => $row->data ?: (object) []),
            Schema\Str::make('createdAt')->nullable()->get(fn (Activity $row) => $row->created_at?->toIso8601String()),
        ];
    }

    /** The page the names were read for (core's Index serializes meta before it reads the rows). */
    public function results(object $query, BaseContext $context): iterable
    {
        return $this->page ?? parent::results($query, $context);
    }

    /**
     * The names for the page's ids, in a fixed number of queries: the page itself (kept for
     * results()), its people, groups, cards and the board's columns.
     *
     * @return array<string, mixed>
     */
    private function names(Context $context): array
    {
        $board = $this->board;
        if (! $board || ! $context->query instanceof Builder) {
            return [];
        }
        $rows = $this->page = $context->query->get();
        $actor = $context->getActor();
        $users = $rows->pluck('user_id')->all();
        $groups = [];
        foreach ($rows as $row) {
            array_push($users, ...array_map('intval', (array) ($row->data['u'] ?? [])));
            if (! empty($row->data['g'])) {
                $groups[] = (int) $row->data['g'];
            }
        }
        $cards = $rows->pluck('card_id')->filter()->unique()->values()->all();

        return [
            'users' => $this->presenter->users($users),
            'groups' => $groups ? $this->presenter->groups($groups, $actor) : (object) [],
            'cards' => $cards ? $this->db->table('corkboard_cards')->where('board_id', $board->id)->whereIn('id', $cards)->pluck('title', 'id') : (object) [],
            'columns' => $this->db->table('corkboard_columns')->where('board_id', $board->id)->pluck('title', 'id'),
        ];
    }
}
