<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Api\Find;
use ErnestDefoe\Corkboard\Discussions;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/corkboard/cards/{id}/discussion   start a discussion from a card and link it */
class StartDiscussion extends Handler
{
    public function __construct(
        private readonly Discussions $discussions,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $card = Find::card($actor, $this->id($request));
        $discussion = $this->discussions->start($card, $actor, $request);

        return ['discussionId' => $discussion->id, 'slug' => $discussion->slug] + $this->presenter->written(Find::card($actor, $card->id));
    }
}
