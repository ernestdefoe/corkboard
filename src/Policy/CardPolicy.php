<?php

namespace ErnestDefoe\Corkboard\Policy;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Card;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * A card follows its board's roles (Access):
 *
 * - update (and its checklist): an editor on an open board;
 * - watch (a write that only follows or unfollows it): anyone signed in who can see it;
 * - comment: a commenter, signed in;
 * - delete: its maker if they can still edit, or a manager.
 */
class CardPolicy extends AbstractPolicy
{
    public function __construct(private readonly Access $access)
    {
    }

    public function update(User $actor, Card $card): bool
    {
        return $this->access->can($card->board, $actor, 'editor');
    }

    public function watch(User $actor, Card $card): bool
    {
        return ! $actor->isGuest() && $this->access->can($card->board, $actor, 'viewer');
    }

    public function comment(User $actor, Card $card): bool
    {
        return ! $actor->isGuest() && $this->access->can($card->board, $actor, 'commenter');
    }

    public function delete(User $actor, Card $card): bool
    {
        $maker = ! $actor->isGuest() && (int) $card->user_id === (int) $actor->id;

        return $this->access->can($card->board, $actor, $maker ? 'editor' : 'manager');
    }
}
