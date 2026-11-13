<?php

namespace ErnestDefoe\Corkboard\Scope;

use ErnestDefoe\Corkboard\Board;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/** A card is visible to whoever may see its board. */
class CardVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('corkboard.moderate')) {
            $query->whereIn('corkboard_cards.board_id', Board::query()->select('corkboard_boards.id'));

            return;
        }
        $query->whereIn('corkboard_cards.board_id', Board::whereVisibleTo($actor)->select('corkboard_boards.id'));
    }
}
