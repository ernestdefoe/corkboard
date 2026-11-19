<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Api\Find;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/** POST /api/corkboard/boards/{id}/pin   pinned: bool (the viewer's own list) */
class PinBoard extends Handler
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        $actor->assertRegistered();
        $board = Find::board($actor, $this->id($request));
        $pinned = (bool) ($this->body($request)['pinned'] ?? true);
        if ($pinned) {
            $position = (int) $this->db->table('corkboard_pins')->where('user_id', $actor->id)->max('position') + 1;
            $this->db->table('corkboard_pins')->updateOrInsert(['user_id' => $actor->id, 'board_id' => $board->id], ['position' => $position]);
        } else {
            $this->db->table('corkboard_pins')->where('user_id', $actor->id)->where('board_id', $board->id)->delete();
        }

        return ['pinned' => $pinned];
    }
}
