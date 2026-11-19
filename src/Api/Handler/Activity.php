<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Api\ActivityPresenter;
use ErnestDefoe\Corkboard\Api\Find;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/corkboard/activity?board=&before=   a board's history, newest first
 * GET /api/corkboard/activity?card=&before=    one card's
 *
 * 30 lines a page, with today's names for the ids in them (ActivityPresenter). A board or card the
 * viewer can't see is a 404.
 */
class Activity extends Handler
{
    public function __construct(private readonly ActivityPresenter $activity)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $before = $this->param($request, 'before') ?: null;
        $cardId = $this->param($request, 'card');
        if ($cardId) {
            $card = Find::card($actor, $cardId);

            return $this->activity->page($card->board, $card->id, $before, $actor);
        }

        return $this->activity->page(Find::board($actor, $this->param($request, 'board')), null, $before, $actor);
    }
}
