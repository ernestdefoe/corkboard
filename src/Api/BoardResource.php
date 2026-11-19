<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Boards;
use ErnestDefoe\Corkboard\Presenter;
use ErnestDefoe\Corkboard\Projects;
use ErnestDefoe\Corkboard\Push;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use WeakMap;

/**
 * `corkboard-boards`. Also the subject of board notifications (title, slug).
 *
 * GET    /api/corkboard-boards?filter[tab]=mine|all|completed|archived|templates&filter[q]=&filter[project]=&page[offset]=
 *        tiles, 30 a page; meta.pinned is the viewer's pinned row (first page of Mine and All);
 *        templates (for people who may use them) by name, each with its columns' titles
 * GET    /api/corkboard-boards/{id}     view: the whole board (Presenter::board), pinned, projects, push
 * POST   /api/corkboard-boards          title, template, visibility, projectId, …; view: the new board
 * PATCH  /api/corkboard-boards/{id}     settings, status, owner; view: the board
 * DELETE /api/corkboard-boards/{id}
 *
 * @extends Resource<Board>
 */
class BoardResource extends Resource
{
    /** Tile fields of the boards on a listed page, worked out for the page at once (BoardList::tiles). */
    private const TILE = ['color', 'visibility', 'status', 'isTemplate', 'ownerId', 'cardCount', 'doneCount', 'version', 'completedAt', 'archivedAt', 'lastActivityAt', 'description', 'role', 'people', 'teamSize', 'dueSoon', 'pinned', 'columns'];

    /** @var WeakMap<Board, array<string, mixed>> */
    private WeakMap $tiles;

    public function __construct(
        private readonly Boards $boards,
        private readonly Presenter $presenter,
        private readonly Access $access,
        private readonly ConnectionInterface $db,
        private readonly Push $push,
        private readonly Projects $projects,
        private readonly BoardList $list,
    ) {
        $this->tiles = new WeakMap();
    }

    public function type(): string
    {
        return 'corkboard-boards';
    }

    public function model(): string
    {
        return Board::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereVisibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        return [
            self::list(BoardList::PAGE, fn (Builder $query, array $filters, Context $context) => $this->list->filter($query, $context->getActor(), $filters))
                ->meta([
                    Schema\Arr::make('pinned')->get(fn (Context $context) => $this->list->pinned(
                        $context->getActor(),
                        (array) ($context->queryParam('filter') ?: []),
                        (int) (((array) ($context->queryParam('page') ?: []))['offset'] ?? 0),
                    )),
                ]),
            Endpoint\Show::make(),
            Endpoint\Create::make()->authenticated(),
            Endpoint\Update::make()->authenticated(),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        // A tile field is read on listed pages only; the ones the browser also writes are declared
        // once, with their rules, and shown on listed pages the same way.
        $onTile = fn (Schema\Attribute $field) => $field
            ->visible(fn (Board $board, Context $context) => $context->listing(self::class))
            ->get(fn (Board $board) => $this->tiles[$board][$field->name] ?? null);
        $written = [
            'description' => self::writeOnly(Schema\Str::make('description')->nullable()),
            'color' => self::writeOnly(Schema\Str::make('color')->nullable()),
            'visibility' => self::writeOnly(Schema\Str::make('visibility')->in(Board::VISIBILITIES)),
            'isTemplate' => self::writeOnly(Schema\Boolean::make('isTemplate')),
            'ownerId' => self::writeOnly(Schema\Integer::make('ownerId'), 'update'),
            'status' => self::writeOnly(Schema\Str::make('status')->in(Board::STATUSES), 'update'),
        ];
        $tile = array_map(fn (string $key) => $onTile($written[$key] ?? Schema\Attribute::make($key)), self::TILE);

        return [
            Schema\Str::make('title')->writable(),
            Schema\Str::make('slug'),
            ...$tile,

            self::writeOnly(Schema\Str::make('template')->nullable(), 'create'),
            self::writeOnly(Schema\Integer::make('tagId')->nullable()),
            self::writeOnly(Schema\Integer::make('projectId')->nullable()),

            self::view(function (Board $board, Context $context) {
                $actor = $context->getActor();
                $out = $this->presenter->board($board, $actor);
                if ($context->showing(self::class)) {
                    if (! $actor->isGuest()) {
                        $out['pinned'] = $this->db->table('corkboard_pins')->where('user_id', $actor->id)->where('board_id', $board->id)->exists();
                        if ($this->access->can($board, $actor, 'manager')) {
                            $out['projects'] = $this->projects->mine($actor);
                        }
                    }
                    $out['push'] = $this->push->available();
                }

                return $out;
            }, true),
        ];
    }

    /** A listed page: its tiles in a fixed number of queries, before any is serialized. */
    public function results(object $query, BaseContext $context): iterable
    {
        $boards = parent::results($query, $context);
        if ($context->listing(self::class)) {
            $models = collect($boards);
            $filters = (array) ($context->queryParam('filter') ?: []);
            $tiles = $this->list->tiles($models, $context->getActor(), BoardList::tab($filters, $context->getActor()) === 'templates');
            foreach ($models as $board) {
                $this->tiles[$board] = $tiles[$board->id];
            }
        }

        return $boards;
    }

    public function create(object $model, BaseContext $context): object
    {
        $board = $this->boards->create($context->getActor(), self::input($context));

        return $board->fresh() ?? $board;
    }

    public function update(object $model, BaseContext $context): object
    {
        return $this->boards->update($model, $context->getActor(), self::input($context));
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->boards->delete($model, $context->getActor());
    }
}
