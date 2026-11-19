<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Api\Find;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/corkboard/boards/{id}/version   the poll: {version}, one query */
class BoardVersion extends Handler
{
    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        return ['version' => (int) Find::board($actor, $this->id($request))->version];
    }
}
