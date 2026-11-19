<?php

namespace ErnestDefoe\Corkboard\Policy;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Board;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * What the board endpoints check before a write (`->can(…)`), from the board's roles (Access):
 *
 * - update: a manager (status changes work on a closed board, settings need it open: Boards);
 * - delete: the owner or a moderator;
 * - addCards: an editor on an open board;
 * - manage (its columns, labels and team): a manager on an open board.
 *
 * The services check the same rules again, with the field-level ones (Refusal) on top.
 */
class BoardPolicy extends AbstractPolicy
{
    public function __construct(private readonly Access $access)
    {
    }

    public function update(User $actor, Board $board): bool
    {
        return $this->access->canManageClosed($board, $actor);
    }

    public function delete(User $actor, Board $board): bool
    {
        return (! $actor->isGuest() && (int) $board->owner_id === (int) $actor->id) || $actor->hasPermission('corkboard.moderate');
    }

    public function addCards(User $actor, Board $board): bool
    {
        return $this->access->can($board, $actor, 'editor');
    }

    public function manage(User $actor, Board $board): bool
    {
        return $this->access->can($board, $actor, 'manager');
    }
}
