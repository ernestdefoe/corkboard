<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Flood control for the writes that reach other people (new cards, comments, boards, team
 * changes): a member's 30th such change inside a minute is refused (429). Moderators aren't
 * limited. Counted from the board history the writes leave anyway: one query, writes only.
 */
class Throttle
{
    private const ROUTES = ['corkboard.cards.create', 'corkboard.cards.comment', 'corkboard.cards.clone', 'corkboard.boards.create', 'corkboard.boards.clone', 'corkboard.team.add', 'corkboard.cards.discussion'];

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if (! in_array($request->getAttribute('routeName'), self::ROUTES, true)) {
            return null;
        }
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || $actor->hasPermission('corkboard.moderate')) {
            return null;
        }

        return $this->db->table('corkboard_activity')->where('user_id', $actor->id)
            ->where('created_at', '>', Carbon::now()->subMinute())->count() >= 30 ? true : null;
    }
}
