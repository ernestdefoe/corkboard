<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Notification\BoardBlueprint;
use ErnestDefoe\Corkboard\Notification\CardBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

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
        $board = $subject instanceof Card ? $subject->board : $subject;
        $ids = [];
        foreach ($users as $user) {
            $id = $user instanceof User ? $user->id : $user;
            if ($id && (! $actor || (int) $id !== (int) $actor->id)) {
                $ids[(int) $id] = true;
            }
        }
        if (! $ids) {
            return;
        }

        $recipients = $this->access->audience($board, User::query()->whereIn('id', array_keys($ids))->with('groups')->get())->all();
        if (! $recipients) {
            return;
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
        if ($rows) {
            $this->db->table('corkboard_outbox')->insert($rows);
        }
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
