<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Group\Group;
use Flarum\User\User;
use ErnestDefoe\Corkboard\Job\SendPush;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells open boards "you're behind" through flarum/realtime, when it's installed.
 *
 * Only ids and the version travel, never a title: a browser that gets one refetches through the
 * API, so the visibility rule is the API's and a misrouted push can't leak anything.
 *
 * Until flarum/framework#5220 lets extensions authorise their own private channels, each push
 * goes to every team member's own `private-user=<id>` channel: for teams up to Settings::maxFanout
 * people (groups counted by their members). Bigger teams, and everyone not on the team, poll.
 * Sent from the queue (Job\SendPush) after the change commits, so nobody waits on the realtime daemon.
 */
class Push
{
    /** @var array<int, array{int, int, int|null, int|null}> board id => [board, version, actor, card] */
    private array $pending = [];

    private bool $registered = false;

    public function __construct(
        private readonly Container $container,
        private readonly ConnectionInterface $db,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly Queue $queue,
    ) {
    }

    public function available(): bool
    {
        // flarum/realtime binds its Pusher client; bound() never autoloads the class.
        return $this->container->bound(\Pusher\Pusher::class);
    }

    public function board(Board $board, ?User $actor, ?int $cardId): void
    {
        if (! $this->available()) {
            return;
        }
        $this->pending[$board->id] = [$board->id, (int) $board->version, $actor?->id, $cardId];

        if ($this->registered) {
            return;
        }
        $this->registered = true;
        // Once the change is committed (at once outside a transaction), one event per board.
        $flush = function () {
            foreach ($this->pending as [$boardId, $version, $actorId, $cardId]) {
                $this->queue->push(new SendPush($boardId, ['t' => 'board', 'b' => $boardId, 'v' => $version, 'u' => $actorId, 'c' => $cardId]));
            }
            $this->pending = [];
            $this->registered = false;
        };
        $this->db instanceof Connection ? $this->db->afterCommit($flush) : $flush();
    }

    /** "Ana is moving this": a short-lived hint, team only. */
    public function moving(Board $board, User $actor, ?int $cardId): void
    {
        if ($this->available()) {
            $this->queue->push(new SendPush($board->id, ['t' => 'moving', 'b' => $board->id, 'u' => $actor->id, 'n' => $actor->display_name, 'c' => $cardId]));
        }
    }

    /**
     * Sends one event to each team member's own channel (SendPush runs this from the queue).
     *
     * @param array<string, mixed> $event
     */
    public function deliver(int $boardId, array $event): void
    {
        try {
            $ids = $this->recipients($boardId);
            if (! $ids) {
                return;
            }
            $pusher = $this->container->make(\Pusher\Pusher::class);
            foreach (array_chunk($ids, 100) as $chunk) {
                $pusher->trigger(array_map(fn (int $id) => 'private-user='.$id, $chunk), 'corkboard', $event);
            }
        } catch (\Throwable $e) {
            // Realtime is down: every open board's poll still brings it.
            $this->logger->warning('[corkboard] realtime push failed: '.$e->getMessage());
        }
    }

    /** @return list<int> */
    public function recipients(int $boardId): array
    {
        $max = $this->settings->maxFanout();
        $board = $this->db->table('corkboard_boards')->where('id', $boardId)->first(['owner_id', 'visibility']);
        if (! $board) {
            return [];
        }
        $rows = $this->db->table('corkboard_members')->where('board_id', $boardId)->get(['user_id', 'group_id']);
        $ids = $rows->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all();
        if ($board->owner_id) {
            $ids[] = (int) $board->owner_id;
        }
        $groups = $board->visibility === 'private' ? [] : $rows->pluck('group_id')->filter()->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => in_array($id, [Group::GUEST_ID, Group::MEMBER_ID], true))->values()->all();
        if ($groups) {
            $ids = array_merge($ids, $this->db->table('group_user')->whereIn('group_id', $groups)->limit($max + 1)->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        }
        $ids = array_values(array_unique($ids));

        return count($ids) > $max ? [] : $ids;
    }
}
