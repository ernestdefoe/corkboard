<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Formatter\Formatter;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Quick notes on a card (commenters and up). People @mentioned are told; so are the card's
 * watchers, except the author and anyone already told by a mention. Writing one watches the card.
 */
class Comments
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Formatter $formatter,
        private readonly Notify $notify,
        private readonly Log $log,
        private readonly Counters $counters,
    ) {
    }

    public function create(Card $card, User $actor, string $text): Comment
    {
        $board = $card->board;
        $this->access->assert($board, $actor, 'commenter');
        $actor->assertRegistered();

        return $this->db->transaction(function () use ($card, $actor, $text, $board) {
            $comment = new Comment();
            $comment->card_id = $card->id;
            $comment->user_id = $actor->id;
            $comment->content = $this->parse($text, $actor);
            $comment->save();
            $this->counters->comments($card);
            $this->notify->watch($card, $actor->id);

            $ref = $this->log->record($board, $card->id, $actor, 'comment_added', ['c' => $comment->id]);
            $mentioned = Notify::mentioned($comment->content);
            $this->notify->send(Notification\MentionedBlueprint::class, $card, $actor, $mentioned, $ref);
            $this->notify->send(Notification\CommentedBlueprint::class, $card, $actor, array_diff($this->notify->watchers($card), $mentioned), $ref);

            return $comment;
        });
    }

    public function update(Comment $comment, Card $card, User $actor, string $text): Comment
    {
        if ($actor->isGuest() || (int) $comment->user_id !== (int) $actor->id || $card->board->isReadOnly()) {
            throw new PermissionDeniedException();
        }
        $this->access->assert($card->board, $actor, 'commenter');
        $before = Notify::mentioned($comment->content);
        $comment->content = $this->parse($text, $actor);
        $comment->edited_at = Carbon::now();
        $comment->save();
        $ref = $this->log->record($card->board, $card->id, $actor, 'comment_edited', ['c' => $comment->id]);
        $this->notify->send(Notification\MentionedBlueprint::class, $card, $actor, array_diff(Notify::mentioned($comment->content), $before), $ref);

        return $comment;
    }

    public function delete(Comment $comment, Card $card, User $actor): void
    {
        $own = ! $actor->isGuest() && (int) $comment->user_id === (int) $actor->id && $this->access->can($card->board, $actor, 'commenter');
        if (! $own && ! $this->access->can($card->board, $actor, 'manager')) {
            throw new PermissionDeniedException();
        }
        $comment->delete();
        $this->counters->comments($card);
        $this->log->record($card->board, $card->id, $actor, 'comment_deleted');
    }

    private function parse(string $text, User $actor): string
    {
        $text = trim($text);
        if ($text === '') {
            throw Refusal::because('comment_empty', 'content');
        }
        if (mb_strlen($text) > 5000) {
            throw Refusal::because('too_long', 'content');
        }

        return $this->formatter->parse($text, null, $actor);
    }
}
