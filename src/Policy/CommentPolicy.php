<?php

namespace ErnestDefoe\Corkboard\Policy;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Comment;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * A comment on a card: its author edits it while they may still comment on an open board; its
 * author or a manager deletes it.
 */
class CommentPolicy extends AbstractPolicy
{
    public function __construct(private readonly Access $access)
    {
    }

    public function update(User $actor, Comment $comment): bool
    {
        return $this->mine($actor, $comment) && $this->access->can($comment->card->board, $actor, 'commenter');
    }

    public function delete(User $actor, Comment $comment): bool
    {
        return $this->access->can($comment->card->board, $actor, $this->mine($actor, $comment) ? 'commenter' : 'manager');
    }

    private function mine(User $actor, Comment $comment): bool
    {
        return ! $actor->isGuest() && (int) $comment->user_id === (int) $actor->id;
    }
}
