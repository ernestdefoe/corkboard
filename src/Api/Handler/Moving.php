<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Api\Find;
use ErnestDefoe\Corkboard\Push;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/corkboard/boards/{id}/moving   cardId|null: "Ana is moving this" to the team
 *
 * A hint, so it's cheap to drop: past PER_WINDOW hints in WINDOW seconds a member's next ones are
 * left out quietly (still a 204, never a 429 the board would show), so a fidgety drag can't fill
 * the queue.
 */
class Moving extends Handler
{
    public const PER_WINDOW = 5;
    public const WINDOW = 10;

    public function __construct(
        private readonly Access $access,
        private readonly Push $push,
        private readonly Cache $cache,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): null
    {
        $board = Find::board($actor, $this->id($request));
        if ($this->access->can($board, $actor, 'editor') && $this->allowed($actor)) {
            $card = $this->body($request)['cardId'] ?? null;
            $this->push->moving($board, $actor, $card ? (int) $card : null);
        }

        return null;
    }

    /** A counter per member that starts with their first hint and lapses WINDOW seconds later. */
    private function allowed(User $actor): bool
    {
        $key = 'corkboard-moving.'.$actor->id;
        $this->cache->add($key, 0, self::WINDOW);

        return (int) $this->cache->increment($key) <= self::PER_WINDOW;
    }
}
