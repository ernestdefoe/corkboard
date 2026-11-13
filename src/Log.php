<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Every change to a board goes through here: a row of activity (ids only; names and titles are
 * read when it's shown, so a rename or a deleted account reads right later), the board's version
 * bumped for the 20-second poll, and a push to the team when flarum/realtime is installed.
 */
class Log
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Push $push,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function record(Board $board, ?int $cardId, ?User $actor, string $type, array $data = []): int
    {
        $id = (int) $this->db->table('corkboard_activity')->insertGetId([
            'board_id' => $board->id,
            'card_id' => $cardId,
            'user_id' => $actor?->exists ? $actor->id : null,
            'type' => $type,
            'data' => $data ? json_encode($data) : null,
            'created_at' => Carbon::now(),
        ]);
        $this->touch($board, $actor, $cardId);

        return $id;
    }

    /** A change worth redrawing for, with no line in the history. */
    public function touch(Board $board, ?User $actor = null, ?int $cardId = null): void
    {
        $now = Carbon::now();
        $this->db->table('corkboard_boards')->where('id', $board->id)->increment('version', 1, ['last_activity_at' => $now]);
        $board->version = (int) $board->version + 1;
        $board->last_activity_at = $now;
        $this->push->board($board, $actor, $cardId);
    }
}
