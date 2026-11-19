<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use Carbon\Carbon;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/corkboard/feed-token   make the viewer's calendar link (a new one: the old link stops working) */
class RenewFeedToken extends Handler
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UrlGenerator $url,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $actor->assertRegistered();
        $token = Str::random(40);
        $this->db->table('corkboard_feed_tokens')->upsert(
            [['user_id' => $actor->id, 'token' => $token, 'created_at' => Carbon::now()]],
            ['user_id'],
            ['token', 'created_at']
        );

        return ['url' => $this->url->to('api')->route('corkboard.feed.ics', ['token' => $token])];
    }
}
