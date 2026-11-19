<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Cards;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use WeakMap;

/**
 * `corkboard-cards`. Also the subject of card notifications (title, boardId, boardTitle,
 * boardSlug): a card's board always comes with it (Card::$with), so a page of them is one query.
 *
 * GET    /api/corkboard-cards?filter[…]=   the card lists (CardList: my cards, due soon, a big column's
 *                                       next cards, archived cards), 50 a page (up to 150); each listed
 *                                       card as the board draws it, where it is, and its people
 * GET    /api/corkboard-cards/{id}       view: everything a card shows (Presenter::card)
 * POST   /api/corkboard-cards            boardId, columnId, title, afterId?, …
 * PATCH  /api/corkboard-cards/{id}       any of: title, description, priority, dueAt, dueAllDay, coverColor,
 *                                        labelIds, assigneeIds, discussionId, columnId + afterId, completed,
 *                                        archived, watching
 * DELETE /api/corkboard-cards/{id}
 *
 * Writes answer (view) with the card as the board draws it plus the board's new version, so the
 * browser that made the change doesn't refetch the board for it.
 *
 * @extends Resource<Card>
 */
class CardResource extends Resource
{
    /** What a listed card shows (CardList::rows), worked out for the page at once. */
    private const ROW = [
        'position', 'priority', 'dueAt', 'dueAllDay', 'completedAt', 'archivedAt', 'ownerId', 'discussionId', 'coverColor',
        'commentCount', 'checklistTotal', 'checklistDone', 'labelIds', 'assigneeIds', 'people', 'columnTitle',
    ];

    /** @var WeakMap<Card, array<string, mixed>> */
    private WeakMap $rows;

    public function __construct(
        private readonly Cards $cards,
        private readonly Presenter $presenter,
        private readonly Access $access,
        private readonly CardList $list,
    ) {
        $this->rows = new WeakMap();
    }

    public function type(): string
    {
        return 'corkboard-cards';
    }

    public function model(): string
    {
        return Card::class;
    }

    /** A card follows its board (CardVisibility, which also leaves out deleted boards). */
    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            self::list(50, fn (Builder $query, array $filters, Context $context) => $this->list->filter($query, $context->getActor(), $filters), Presenter::SLICE),
            Endpoint\Show::make(),
            self::createOn('boardId', 'addCards'),
            // Following a card only needs to see it; any other change needs an editor (CardPolicy).
            Endpoint\Update::make()->authenticated()->can(fn (Card $card, Context $context) => match (array_keys(self::input($context))) {
                [] => null,
                ['watching'] => 'watch',
                default => 'update',
            }),
            Endpoint\Delete::make()->authenticated()->can('delete'),
        ];
    }

    public function fields(): array
    {
        // A listed card's fields are read on listed pages only; the ones the browser also writes are
        // declared once, with their rules, and shown on listed pages the same way.
        $onRow = fn (Schema\Attribute $field) => $field
            ->visible(fn (Card $card, Context $context) => $context->listing(self::class))
            ->get(fn (Card $card) => $this->rows[$card][$field->name] ?? null);
        $written = [
            'priority' => self::writeOnly(Schema\Str::make('priority')->in(Card::PRIORITIES)),
            // A date Carbon reads (ISO 8601 from the browser); anything else is a 422, not a 500.
            'dueAt' => self::writeOnly(Schema\Str::make('dueAt')->nullable()->rule('date')),
            'dueAllDay' => self::writeOnly(Schema\Boolean::make('dueAllDay')),
            'coverColor' => self::writeOnly(Schema\Str::make('coverColor')->nullable()),
            'labelIds' => self::writeOnly(Schema\Arr::make('labelIds')),
            'assigneeIds' => self::writeOnly(Schema\Arr::make('assigneeIds')),
            'discussionId' => self::writeOnly(Schema\Integer::make('discussionId')->nullable()),
        ];

        return [
            Schema\Str::make('title')->writable(),
            Schema\Integer::make('boardId')->property('board_id')->writableOnCreate()->requiredOnCreate(),
            Schema\Str::make('boardTitle')->get(fn (Card $card) => $card->board->title),
            Schema\Str::make('boardSlug')->get(fn (Card $card) => $card->board->slug),
            Schema\Str::make('boardColor')->get(fn (Card $card) => $card->board->color)->visible(fn (Card $card, Context $context) => $context->listing(self::class)),
            Schema\Integer::make('columnId')->property('column_id')->writable(),
            ...array_map(fn (string $key) => $onRow($written[$key] ?? Schema\Attribute::make($key)), self::ROW),

            self::writeOnly(Schema\Integer::make('afterId')->nullable()),
            self::writeOnly(Schema\Str::make('description')->nullable()),
            self::writeOnly(Schema\Boolean::make('completed')),
            self::writeOnly(Schema\Boolean::make('archived')),
            self::writeOnly(Schema\Boolean::make('watching'), 'update'),

            self::view(function (Card $card, Context $context) {
                $actor = $context->getActor();
                if ($context->showing(self::class)) {
                    return $this->presenter->card($card, $actor, $context->request) + ['abilities' => $this->access->abilities($card->board, $actor), 'version' => $card->board->version];
                }

                // Read again after the write: its board's counts and version moved on.
                return $this->presenter->written(Find::card($actor, $card->id));
            }, true),
        ];
    }

    /** A listed page: what its cards show, in a fixed number of queries, before any is serialized. */
    public function results(object $query, BaseContext $context): iterable
    {
        $cards = parent::results($query, $context);
        if ($context->listing(self::class)) {
            $models = collect($cards);
            $rows = $this->list->rows($models);
            foreach ($models as $card) {
                $this->rows[$card] = $rows[$card->id];
            }
        }

        return $cards;
    }

    public function create(object $model, BaseContext $context): object
    {
        $in = self::input($context);
        $board = Find::board($context->getActor(), (int) $in['boardId']);
        unset($in['boardId']);

        return $this->cards->create($board, $context->getActor(), $in);
    }

    public function update(object $model, BaseContext $context): object
    {
        return $this->cards->update($model, $context->getActor(), self::input($context));
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->cards->delete($model, $context->getActor());
    }
}
