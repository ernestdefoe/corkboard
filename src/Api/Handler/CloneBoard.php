<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Api\Find;
use ErnestDefoe\Corkboard\Boards;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/corkboard/boards/{id}/clone   title, cards (bool), due ('clear' | days) → {id, slug} */
class CloneBoard extends Handler
{
    public function __construct(private readonly Boards $boards)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->boards->clone(Find::board($actor, $this->id($request)), $actor, $this->body($request));

        return ['id' => $board->id, 'slug' => $board->slug];
    }
}
