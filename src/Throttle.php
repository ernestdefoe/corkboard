<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Flood control for the writes that reach other people. On the routes below (creating a card,
 * comment, board or team row, cloning a card or board, starting a discussion from a card), a
 * member is refused (429) once their history holds 30 rows of the kinds those writes leave
 * (Throttle::COUNTED) from the last minute. Drags, edits, ticks and other changes log rows too
 * but don't count, so reorganising a board never blocks adding a card. Moderators aren't limited.
 * One query, on those routes only.
 */
class Throttle
{
    private const ROUTES = [
        'corkboard-cards.create', 'corkboard-comments.create', 'corkboard-boards.create', 'corkboard-members.create',
        'corkboard.cards.clone', 'corkboard.boards.clone', 'corkboard.cards.discussion',
    ];

    /** The history rows the routes above leave (card_linked: a discussion started from a card, or a card linked to one). */
    public const COUNTED = ['card_created', 'comment_added', 'board_created', 'member_added', 'card_cloned', 'board_cloned', 'card_linked'];

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

        return $this->db->table('corkboard_activity')->where('user_id', $actor->id)->whereIn('type', self::COUNTED)
            ->where('created_at', '>', Carbon::now()->subMinute())->count() >= 30 ? true : null;
    }
}
