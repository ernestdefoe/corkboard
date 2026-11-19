<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/corkboard/feed-token   the viewer's private calendar link, or null before they make one.
 * A GET changes nothing: an image tag on another site can't make one.
 */
class FeedToken extends Handler
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UrlGenerator $url,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $actor->assertRegistered();
        $token = $this->db->table('corkboard_feed_tokens')->where('user_id', $actor->id)->value('token');

        return ['url' => $token ? $this->url->to('api')->route('corkboard.feed.ics', ['token' => $token]) : null];
    }
}
