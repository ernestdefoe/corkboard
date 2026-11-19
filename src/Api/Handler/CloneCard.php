<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Api\Find;
use ErnestDefoe\Corkboard\Cards;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/corkboard/cards/{id}/clone   boardId?, columnId? → the copy as the board draws it */
class CloneCard extends Handler
{
    public function __construct(
        private readonly Cards $cards,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $copy = $this->cards->clone(Find::card($actor, $this->id($request)), $actor, $this->body($request));

        return $this->presenter->written(Find::card($actor, $copy->id));
    }
}
