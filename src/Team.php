<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * A board's team (managers only): people by name and, except on private boards, whole groups,
 * each with a role. The owner is always a manager and isn't a row here.
 */
class Team
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Log $log,
        private readonly Notify $notify,
    ) {
    }

    /** @param array<string, mixed> $in userId or groupId, role */
    public function add(Board $board, User $actor, array $in): Member
    {
        $this->access->assert($board, $actor, 'manager');
        $role = $this->role($in['role'] ?? 'editor');

        $userId = isset($in['userId']) ? (int) $in['userId'] : null;
        $groupId = isset($in['groupId']) ? (int) $in['groupId'] : null;
        if ($userId) {
            $user = User::query()->find($userId);
            if (! $user || (int) $board->owner_id === $userId) {
                throw Refusal::because('member', 'userId');
            }
        } elseif ($groupId) {
            if ($board->visibility === 'private') {
                throw Refusal::because('private_group', 'groupId');
            }
            // Guests are never a team: they can't sign in to act.
            if ($groupId === Group::GUEST_ID || ! Group::query()->whereKey($groupId)->exists()) {
                throw Refusal::because('member', 'groupId');
            }
        } else {
            throw Refusal::because('member', 'userId');
        }

        if ($this->db->table('corkboard_members')->where('board_id', $board->id)->count() >= 200) {
            throw Refusal::because('team_full', 'userId');
        }

        // One row per person or group (unique keys): adding again sets the role, and two adds at once
        // (a double click) still make one row.
        $key = $userId ? 'user_id' : 'group_id';
        $find = fn () => Member::query()->where('board_id', $board->id)->where($key, $userId ?: $groupId)->first();
        $isNew = $find() === null;
        $this->db->table('corkboard_members')->upsert(
            [['board_id' => $board->id, 'user_id' => $userId, 'group_id' => $userId ? null : $groupId, 'role' => $role, 'created_at' => Carbon::now()]],
            ['board_id', $key],
            ['role'],
        );
        /** @var Member $member */
        $member = $find();
        // The actor's own role may have changed: the board this answers with must say so.
        $this->access->forget($board, $actor);

        $ref = $this->log->record($board, null, $actor, $isNew ? 'member_added' : 'member_role', array_filter(['u' => $userId, 'g' => $member->group_id, 'r' => $role]));
        if ($userId) {
            $this->notify->send(Notification\TeamAddedBlueprint::class, $board, $actor, [$userId], $ref);
        }

        return $member;
    }

    public function update(Member $member, Board $board, User $actor, string $role): Member
    {
        $this->access->assert($board, $actor, 'manager');
        $member->role = $this->role($role);
        $member->save();
        $this->access->forget($board, $actor);
        $ref = $this->log->record($board, null, $actor, 'member_role', array_filter(['u' => $member->user_id, 'g' => $member->group_id, 'r' => $member->role]));
        if ($member->user_id) {
            $this->notify->send(Notification\TeamAddedBlueprint::class, $board, $actor, [$member->user_id], $ref);
        }

        return $member;
    }

    public function remove(Member $member, Board $board, User $actor): void
    {
        // Anyone may leave a team; taking someone else off is for managers.
        if ($actor->isGuest() || (int) $member->user_id !== (int) $actor->id) {
            $this->access->assert($board, $actor, 'manager');
        }
        $member->delete();
        $this->access->forget($board, $actor);
        $this->log->record($board, null, $actor, 'member_removed', array_filter(['u' => $member->user_id, 'g' => $member->group_id]));
    }

    private function role(mixed $role): string
    {
        if (! in_array($role, Member::ROLES, true)) {
            throw Refusal::because('role', 'role');
        }

        return (string) $role;
    }
}
