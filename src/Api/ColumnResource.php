<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Column;
use ErnestDefoe\Corkboard\Presenter;
use ErnestDefoe\Corkboard\Structure;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * `corkboard-columns`, which follow their board.
 *
 * GET    /api/corkboard-columns?filter[board]=   GET /api/corkboard-columns/{id}
 * POST   /api/corkboard-columns        boardId, title, afterId?, isDone, wipLimit, color, autoArchiveDays
 * PATCH  /api/corkboard-columns/{id}   any of those but boardId
 * DELETE /api/corkboard-columns/{id}   (only an empty column)
 *
 * Writes answer (view) with the whole board.
 *
 * @extends Resource<Column>
 */
class ColumnResource extends Resource
{
    public function __construct(
        private readonly Structure $structure,
        private readonly Presenter $presenter,
    ) {
    }

    public function type(): string
    {
        return 'corkboard-columns';
    }

    public function model(): string
    {
        return Column::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereIn('corkboard_columns.board_id', Board::whereVisibleTo($context->getActor())->select('corkboard_boards.id'));
    }

    public function endpoints(): array
    {
        return [
            self::list(50, function (Builder $query, array $filters) {
                if (isset($filters['board']) && is_numeric($filters['board'])) {
                    $query->where('corkboard_columns.board_id', (int) $filters['board']);
                }
                $query->orderBy('corkboard_columns.board_id')->orderBy('corkboard_columns.position');
            }),
            Endpoint\Show::make(),
            self::createOn('boardId', 'manage'),
            Endpoint\Update::make()->authenticated()->can('update'),
            Endpoint\Delete::make()->authenticated()->can('delete'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('boardId')->property('board_id')->writableOnCreate()->requiredOnCreate(),
            Schema\Str::make('title')->writable(),
            Schema\Integer::make('position'),
            Schema\Boolean::make('isDone')->property('is_done')->writable(),
            Schema\Integer::make('wipLimit')->property('wip_limit')->nullable()->writable(),
            Schema\Str::make('color')->nullable()->writable(),
            Schema\Integer::make('autoArchiveDays')->property('auto_archive_days')->nullable()->writable(),
            self::writeOnly(Schema\Integer::make('afterId')->nullable()),

            self::view(fn (Column $column, Context $context) => $this->presenter->board(Find::board($context->getActor(), $column->board_id), $context->getActor())),
        ];
    }

    public function create(object $model, BaseContext $context): object
    {
        $in = self::input($context);
        $board = Find::board($context->getActor(), (int) $in['boardId']);
        unset($in['boardId']);

        return $this->structure->addColumn($board, $context->getActor(), $in);
    }

    public function update(object $model, BaseContext $context): object
    {
        return $this->structure->updateColumn($model, Find::board($context->getActor(), $model->board_id), $context->getActor(), self::input($context));
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->structure->deleteColumn($model, Find::board($context->getActor(), $model->board_id), $context->getActor());
    }
}
