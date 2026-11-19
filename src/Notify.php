<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Notification\BoardBlueprint;
use ErnestDefoe\Corkboard\Notification\CardBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Sends Corkboard's alerts, and queues their emails in the outbox (Mail\Outbox sends one email
 * per person every few minutes, so twelve assignments are one email).
 *
 * Every recipient is checked against the board's visibility at the moment it's sent: someone
 * taken off a team hears nothing more about the board. The person who did it is never told.
 */
class Notify
{
    public function __construct(
        private readonly NotificationSyncer $syncer,
        private readonly ConnectionInterface $db,
        private readonly Access $access,
    ) {
    }

    /**
     * @param class-string<CardBlueprint|BoardBlueprint> $blueprint
     * @param iterable<User|int|null> $users
     */
    public function send(string $blueprint, Card|Board $subject, ?User $actor, iterable $users, int $ref): void
    {
        $ids = self::ids($users, $actor);
        if (! $ids) {
            return;
        }
        $people = User::query()->whereIn('id', $ids)->with('groups')->get();
        $this->queueEmail($this->deliver($blueprint, $subject, $actor, $people, $ref, null));
    }

    /**
     * The same alert about many cards (the hourly reminders): everyone named and every board's team
     * rows are read once for all of them, and the emails queued in one insert.
     *
     * @param class-string<CardBlueprint> $blueprint
     * @param list<array{Card, list<int|null>, int}> $sends each card, who to tell, its ref
     */
    public function sendEach(string $blueprint, array $sends): void
    {
        $all = [];
        foreach ($sends as [, $users]) {
            $all = array_merge($all, self::ids($users, null));
        }
        if (! $all) {
            return;
        }
        $people = User::query()->whereIn('id', array_unique($all))->with('groups')->get()->keyBy('id');
        $boardIds = array_values(array_unique(array_map(fn (array $send) => $send[0]->board_id, $sends)));
        $teams = $this->db->table('corkboard_members')->whereIn('board_id', $boardIds)->get(['board_id', 'user_id', 'group_id'])->groupBy('board_id');

        $rows = [];
        foreach ($sends as [$card, $users, $ref]) {
            $mine = $people->only(self::ids($users, null))->values();
            array_push($rows, ...$this->deliver($blueprint, $card, null, $mine, $ref, $teams[$card->board_id] ?? collect()));
        }
        $this->queueEmail($rows);
    }

    /**
     * Alerts to those of these people who may see the board now, and the outbox rows for the ones
     * who want it by email.
     *
     * @param class-string<CardBlueprint|BoardBlueprint> $blueprint
     * @param EloquentCollection<int, User> $people loaded with their groups
     * @param Collection<int, object>|null $team the board's team rows, if already read
     * @return list<array<string, mixed>>
     */
    private function deliver(string $blueprint, Card|Board $subject, ?User $actor, EloquentCollection $people, int $ref, ?Collection $team): array
    {
        $board = $subject instanceof Card ? $subject->board : $subject;
        $recipients = $this->access->audience($board, $people, $team)->all();
        if (! $recipients) {
            return [];
        }
        $this->syncer->sync(new $blueprint($subject, $actor, $ref), $recipients);

        $type = $blueprint::getType();
        $rows = [];
        foreach ($recipients as $user) {
            if ($user->getPreference('notify_'.$type.'_email') && $user->getPreference('notify_corkboardEmail_email', true) !== false && $user->is_email_confirmed) {
                $rows[] = [
                    'user_id' => $user->id,
                    'board_id' => $board->id,
                    'card_id' => $subject instanceof Card ? $subject->id : null,
                    'actor_id' => $actor?->id,
                    'type' => $type,
                    'created_at' => Carbon::now(),
                ];
            }
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function queueEmail(array $rows): void
    {
        if ($rows) {
            $this->db->table('corkboard_outbox')->insert($rows);
        }
    }

    /**
     * The people to tell, never the one who did it.
     *
     * @param iterable<User|int|null> $users
     * @return list<int>
     */
    private static function ids(iterable $users, ?User $actor): array
    {
        $ids = [];
        foreach ($users as $user) {
            $id = $user instanceof User ? $user->id : $user;
            if ($id && (! $actor || (int) $id !== (int) $actor->id)) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * People mentioned in formatter XML (flarum/mentions' USERMENTION tags).
     *
     * @return list<int>
     */
    public static function mentioned(?string $xml): array
    {
        if (! $xml || ! str_contains($xml, '<USERMENTION')) {
            return [];
        }

        return array_values(array_unique(array_map('intval', \s9e\TextFormatter\Utils::getAttributeValues($xml, 'USERMENTION', 'id'))));
    }

    /** The people watching a card (assignees, its owner and commenters, unless they switched it off). @return list<int> */
    public function watchers(Card $card): array
    {
        return $this->db->table('corkboard_watchers')->where('card_id', $card->id)->where('watching', true)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Watch a card, unless they switched watching it off before.
     *
     * @param int|list<int> $userIds
     */
    public function watch(Card $card, int|array $userIds): void
    {
        $rows = array_map(fn (int $id) => ['card_id' => $card->id, 'user_id' => $id, 'watching' => true], (array) $userIds);
        $rows && $this->db->table('corkboard_watchers')->insertOrIgnore($rows);
    }
}
