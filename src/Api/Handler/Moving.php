<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Api\Find;
use ErnestDefoe\Corkboard\Push;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/corkboard/boards/{id}/moving   cardId|null: "Ana is moving this" to the team */
class Moving extends Handler
{
    public function __construct(
        private readonly Access $access,
        private readonly Push $push,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): null
    {
        $board = Find::board($actor, $this->id($request));
        if ($this->access->can($board, $actor, 'editor')) {
            $card = $this->body($request)['cardId'] ?? null;
            $this->push->moving($board, $actor, $card ? (int) $card : null);
        }

        return null;
    }
}
