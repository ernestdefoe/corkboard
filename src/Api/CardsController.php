<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Cards;
use ErnestDefoe\Corkboard\ChecklistItem;
use ErnestDefoe\Corkboard\Checklist;
use ErnestDefoe\Corkboard\Comment;
use ErnestDefoe\Corkboard\Comments;
use ErnestDefoe\Corkboard\Discussions;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /corkboard/boards/{id}/cards        columnId, title, afterId?, …
 * GET    /corkboard/cards/{id}               everything a card shows (Presenter::card)
 * PATCH  /corkboard/cards/{id}               any of: title, description, priority, dueAt, dueAllDay, coverColor,
 *                                            labelIds, assigneeIds, discussionId, columnId + afterId, completed,
 *                                            archived, watching
 * DELETE /corkboard/cards/{id}
 * POST   /corkboard/cards/{id}/clone         boardId?, columnId?
 * POST   /corkboard/cards/{id}/discussion    start a discussion from it
 * GET    /corkboard/cards/{id}/comments?before=
 * POST   /corkboard/cards/{id}/comments      content
 * PATCH  /corkboard/comments/{id}            content         DELETE /corkboard/comments/{id}
 * GET    /corkboard/cards/{id}/activity?before=
 * POST   /corkboard/cards/{id}/checklist     text, afterId?
 * PATCH  /corkboard/checklist/{id}           text, isDone, afterId, userId   DELETE /corkboard/checklist/{id}
 *
 * Writes answer with the card as the board draws it (slim) plus the board's new version, so the
 * browser that made the change doesn't refetch the board for it.
 */
class CardsController extends Controller
{
    public function __construct(
        private readonly Cards $cards,
        private readonly Comments $comments,
        private readonly Checklist $checklist,
        private readonly Discussions $discussions,
        private readonly Presenter $presenter,
        private readonly Access $access,
        private readonly ActivityPresenter $activity,
    ) {
    }

    /** @return array<string, mixed> */
    protected function create(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);
        $card = $this->cards->create($board, $actor, $this->body($request));

        return $this->written($request, $actor, $card->id);
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request, User $actor): array
    {
        $card = $this->card($request, $actor);

        return $this->presenter->card($card, $actor, $request) + ['abilities' => $this->access->abilities($card->board, $actor), 'version' => $card->board->version];
    }

    /** @return array<string, mixed> */
    protected function update(ServerRequestInterface $request, User $actor): array
    {
        $card = $this->cards->update($this->card($request, $actor), $actor, $this->body($request));

        return $this->written($request, $actor, $card->id);
    }

    protected function delete(ServerRequestInterface $request, User $actor): null
    {
        $this->cards->delete($this->card($request, $actor), $actor);

        return null;
    }

    /** @return array<string, mixed> */
    protected function clone(ServerRequestInterface $request, User $actor): array
    {
        $copy = $this->cards->clone($this->card($request, $actor), $actor, $this->body($request));

        return $this->written($request, $actor, $copy->id);
    }

    /** @return array<string, mixed> */
    protected function discussion(ServerRequestInterface $request, User $actor): array
    {
        $card = $this->card($request, $actor);
        $discussion = $this->discussions->start($card, $actor, $request);

        return ['discussionId' => $discussion->id, 'slug' => $discussion->slug] + $this->written($request, $actor, $card->id);
    }

    /** @return array<string, mixed> */
    protected function comments(ServerRequestInterface $request, User $actor): array
    {
        $card = $this->card($request, $actor);
        $page = $this->presenter->comments($card, $actor, $request, $this->param($request, 'before') ?: null);

        return ['comments' => $page['items'], 'more' => $page['more'], 'users' => $this->presenter->users($page['userIds'])];
    }

    /** @return array<string, mixed> */
    protected function comment(ServerRequestInterface $request, User $actor): array
    {
        $card = $this->card($request, $actor);
        $this->comments->create($card, $actor, (string) ($this->body($request)['content'] ?? ''));

        return $this->comments($request->withQueryParams([]), $actor) + ['commentCount' => $card->comment_count, 'version' => $card->board->version];
    }

    /** @return array<string, mixed> */
    protected function editComment(ServerRequestInterface $request, User $actor): array
    {
        [$comment, $card] = $this->commentAndCard($request, $actor);
        $this->comments->update($comment, $card, $actor, (string) ($this->body($request)['content'] ?? ''));

        return $this->comments($request->withAttribute('routeParameters', ['id' => $card->id])->withQueryParams([]), $actor);
    }

    protected function deleteComment(ServerRequestInterface $request, User $actor): null
    {
        [$comment, $card] = $this->commentAndCard($request, $actor);
        $this->comments->delete($comment, $card, $actor);

        return null;
    }

    /** @return array<string, mixed> */
    protected function activity(ServerRequestInterface $request, User $actor): array
    {
        $card = $this->card($request, $actor);

        return $this->activity->page($card->board, $card->id, $this->param($request, 'before') ?: null);
    }

    /** @return array<string, mixed> */
    protected function addItem(ServerRequestInterface $request, User $actor): array
    {
        $card = $this->card($request, $actor);
        $this->checklist->add($card, $actor, $this->body($request));

        return $this->items($card);
    }

    /** @return array<string, mixed> */
    protected function editItem(ServerRequestInterface $request, User $actor): array
    {
        $item = ChecklistItem::query()->findOrFail($this->id($request));
        $card = $this->card($request, $actor, $item->card_id);
        $this->checklist->update($item, $card, $actor, $this->body($request));

        return $this->items($card);
    }

    protected function deleteItem(ServerRequestInterface $request, User $actor): null
    {
        $item = ChecklistItem::query()->findOrFail($this->id($request));
        $card = $this->card($request, $actor, $item->card_id);
        $this->checklist->delete($item, $card, $actor);

        return null;
    }

    /** @return array<string, mixed> */
    private function items(\ErnestDefoe\Corkboard\Card $card): array
    {
        $items = ChecklistItem::query()->where('card_id', $card->id)->orderBy('position')->orderBy('id')->get();

        return [
            'checklist' => $items->map(fn (ChecklistItem $i) => ['id' => $i->id, 'text' => $i->text, 'isDone' => $i->is_done, 'userId' => $i->user_id])->values()->all(),
            'checklistDone' => $card->checklist_done,
            'checklistTotal' => $card->checklist_total,
            'version' => $card->board->version,
        ];
    }

    /** @return array{Comment, \ErnestDefoe\Corkboard\Card} */
    private function commentAndCard(ServerRequestInterface $request, User $actor): array
    {
        $comment = Comment::query()->findOrFail($this->id($request));

        return [$comment, $this->card($request, $actor, $comment->card_id)];
    }

    /** @return array<string, mixed> the card as the board draws it, and the board's version */
    private function written(ServerRequestInterface $request, User $actor, int $id): array
    {
        $card = $this->card($request, $actor, $id);
        [$slim, $users] = $this->presenter->slim(collect([(object) $card->getAttributes()]));

        return [
            'card' => $slim[0],
            'users' => $this->presenter->users($users),
            'version' => (int) $card->board->version,
            'cardCount' => $card->board->card_count,
            'doneCount' => $card->board->done_count,
        ];
    }
}
