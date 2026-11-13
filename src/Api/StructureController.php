<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Column;
use ErnestDefoe\Corkboard\Label;
use ErnestDefoe\Corkboard\Presenter;
use ErnestDefoe\Corkboard\Structure;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /corkboard/boards/{id}/columns   PATCH|DELETE /corkboard/columns/{id}
 * POST /corkboard/boards/{id}/labels    PATCH|DELETE /corkboard/labels/{id}
 *
 * Each answers with the whole board.
 */
class StructureController extends Controller
{
    public function __construct(
        private readonly Structure $structure,
        private readonly Presenter $presenter,
    ) {
    }

    /** @return array<string, mixed> */
    protected function addColumn(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);
        $this->structure->addColumn($board, $actor, $this->body($request));

        return $this->presenter->board($board, $actor);
    }

    /** @return array<string, mixed> */
    protected function editColumn(ServerRequestInterface $request, User $actor): array
    {
        $column = Column::query()->findOrFail($this->id($request));
        $board = $this->board($request, $actor, $column->board_id);
        $this->structure->updateColumn($column, $board, $actor, $this->body($request));

        return $this->presenter->board($board, $actor);
    }

    /** @return array<string, mixed> */
    protected function deleteColumn(ServerRequestInterface $request, User $actor): array
    {
        $column = Column::query()->findOrFail($this->id($request));
        $board = $this->board($request, $actor, $column->board_id);
        $this->structure->deleteColumn($column, $board, $actor);

        return $this->presenter->board($board, $actor);
    }

    /** @return array<string, mixed> */
    protected function addLabel(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);
        $this->structure->addLabel($board, $actor, $this->body($request));

        return $this->presenter->board($board, $actor);
    }

    /** @return array<string, mixed> */
    protected function editLabel(ServerRequestInterface $request, User $actor): array
    {
        $label = Label::query()->findOrFail($this->id($request));
        $board = $this->board($request, $actor, $label->board_id);
        $this->structure->updateLabel($label, $board, $actor, $this->body($request));

        return $this->presenter->board($board, $actor);
    }

    /** @return array<string, mixed> */
    protected function deleteLabel(ServerRequestInterface $request, User $actor): array
    {
        $label = Label::query()->findOrFail($this->id($request));
        $board = $this->board($request, $actor, $label->board_id);
        $this->structure->deleteLabel($label, $board, $actor);

        return $this->presenter->board($board, $actor);
    }
}
