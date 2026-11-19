<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Label;
use ErnestDefoe\Corkboard\Presenter;
use ErnestDefoe\Corkboard\Structure;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * `corkboard-labels`, which follow their board.
 *
 * GET /api/corkboard-labels/{id}   POST /api/corkboard-labels (boardId, name, color)
 * PATCH|DELETE /api/corkboard-labels/{id}
 *
 * Writes answer (view) with the whole board.
 *
 * @extends Resource<Label>
 */
class LabelResource extends Resource
{
    public function __construct(
        private readonly Structure $structure,
        private readonly Presenter $presenter,
    ) {
    }

    public function type(): string
    {
        return 'corkboard-labels';
    }

    public function model(): string
    {
        return Label::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereIn('corkboard_labels.board_id', Board::whereVisibleTo($context->getActor())->select('corkboard_boards.id'));
    }

    public function endpoints(): array
    {
        return [
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
            Schema\Str::make('name')->nullable()->writable(),
            Schema\Str::make('color')->writable(),

            self::view(fn (Label $label, Context $context) => $this->presenter->board(Find::board($context->getActor(), $label->board_id), $context->getActor())),
        ];
    }

    public function create(object $model, BaseContext $context): object
    {
        $in = self::input($context);
        $board = Find::board($context->getActor(), (int) $in['boardId']);
        unset($in['boardId']);

        return $this->structure->addLabel($board, $context->getActor(), $in);
    }

    public function update(object $model, BaseContext $context): object
    {
        return $this->structure->updateLabel($model, Find::board($context->getActor(), $model->board_id), $context->getActor(), self::input($context));
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->structure->deleteLabel($model, Find::board($context->getActor(), $model->board_id), $context->getActor());
    }
}
