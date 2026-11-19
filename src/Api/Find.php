<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Card;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A board or card by id, only if the viewer may see it: anything else is a 404, never a 403, so an
 * id says nothing about what's behind it.
 */
final class Find
{
    public static function board(User $actor, int $id): Board
    {
        return Board::whereVisibleTo($actor)->findOrFail($id);
    }

    public static function card(User $actor, int $id): Card
    {
        $card = Card::whereVisibleTo($actor)->findOrFail($id);
        // Its board is soft-deleted: gone for everyone.
        if ($card->board->deleted_at !== null) {
            throw new ModelNotFoundException();
        }

        return $card;
    }
}
