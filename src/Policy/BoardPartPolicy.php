<?php

namespace ErnestDefoe\Corkboard\Policy;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Column;
use ErnestDefoe\Corkboard\Label;
use ErnestDefoe\Corkboard\Member;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * A board's columns, labels and team rows: changed by its managers, on an open board. Anyone may
 * take themselves off a team (delete their own team row).
 */
class BoardPartPolicy extends AbstractPolicy
{
    public function __construct(private readonly Access $access)
    {
    }

    public function update(User $actor, Column|Label|Member $part): bool
    {
        return $this->manages($actor, $part);
    }

    public function delete(User $actor, Column|Label|Member $part): bool
    {
        $leaving = $part instanceof Member && ! $actor->isGuest() && (int) $part->user_id === (int) $actor->id;

        return $leaving || $this->manages($actor, $part);
    }

    private function manages(User $actor, Column|Label|Member $part): bool
    {
        // The endpoint found the part through its board's visibility, so the board is there.
        $board = Board::query()->find($part->board_id);

        return $board !== null && $this->access->can($board, $actor, 'manager');
    }
}
