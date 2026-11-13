<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Group\Group;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use WeakMap;

/**
 * What someone may do on a board. Roles rank viewer < commenter < editor < manager:
 *
 * - moderators (`corkboard.moderate`) and the owner are managers;
 * - a team row by name, or for one of their groups, gives that row's role (the highest wins;
 *   private boards take people by name only);
 * - anyone else who may see the board (public, or members and signed in) is a viewer.
 *
 * Completed and archived boards are read-only for everyone: only a manager's "reopen" works.
 * Roles are worked out once per board per request (memo keyed by the actor object, which lives
 * for one request), with one query for the team rows.
 */
class Access
{
    public const RANK = ['viewer' => 1, 'commenter' => 2, 'editor' => 3, 'manager' => 4];

    /**
     * Shared by every Access instance (the container makes a new one per class that asks), keyed
     * by the actor object, which lives for one request.
     *
     * @var WeakMap<User, array<int, string|null>>|null
     */
    private static ?WeakMap $roles = null;

    /** @var WeakMap<User, array<int, string|null>> */
    private WeakMap $memo;

    public function __construct(private readonly ConnectionInterface $db)
    {
        $this->memo = self::$roles ??= new WeakMap();
    }

    /**
     * The groups a team row can name for this person: their own, plus Members (everyone signed
     * in) and Guests, which Flarum doesn't store per user.
     *
     * @return list<int>
     */
    public static function groupIds(User $actor): array
    {
        if ($actor->isGuest()) {
            return [Group::GUEST_ID];
        }

        return array_values(array_unique(array_merge([Group::GUEST_ID, Group::MEMBER_ID], $actor->groups->pluck('id')->map(fn ($id) => (int) $id)->all())));
    }

    public function role(Board $board, User $actor): ?string
    {
        $this->memo[$actor] ??= [];
        if (! array_key_exists($board->id, $this->memo[$actor])) {
            $this->prime([$board], $actor);
        }

        return $this->memo[$actor][$board->id];
    }

    /**
     * Roles for many boards in one query (the boards list).
     *
     * @param iterable<Board> $boards
     */
    public function prime(iterable $boards, User $actor): void
    {
        $this->memo[$actor] ??= [];
        $byId = [];
        foreach ($boards as $board) {
            $byId[$board->id] = $board;
        }
        if (! $byId) {
            return;
        }

        $moderator = $actor->hasPermission('corkboard.moderate');
        $rows = [];
        if (! $actor->isGuest() && ! $moderator) {
            $groups = self::groupIds($actor);
            $rows = $this->db->table('corkboard_members')
                ->whereIn('board_id', array_keys($byId))
                ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhereIn('group_id', $groups))
                ->get(['board_id', 'user_id', 'role']);
        }

        $best = [];
        foreach ($rows as $row) {
            $board = $byId[(int) $row->board_id];
            // Private boards take people by name, never a whole group.
            if ($row->user_id === null && $board->visibility === 'private') {
                continue;
            }
            $rank = self::RANK[$row->role] ?? 0;
            if ($rank > (self::RANK[$best[$board->id] ?? ''] ?? 0)) {
                $best[$board->id] = $row->role;
            }
        }

        foreach ($byId as $id => $board) {
            if ($moderator || (! $actor->isGuest() && (int) $board->owner_id === (int) $actor->id)) {
                $role = 'manager';
            } elseif (isset($best[$id])) {
                $role = $best[$id];
            } elseif ($actor->hasPermission('corkboard.view') && ($board->visibility === 'public' || ($board->visibility === 'members' && ! $actor->isGuest()))) {
                $role = 'viewer';
            } else {
                $role = null;
            }
            $this->memo[$actor][$id] = $role;
        }
    }

    public function forget(Board $board, User $actor): void
    {
        if (isset($this->memo[$actor])) {
            $roles = $this->memo[$actor];
            unset($roles[$board->id]);
            $this->memo[$actor] = $roles;
        }
    }

    /** At least this role, and the board open for changes (unless only viewing). */
    public function can(Board $board, User $actor, string $need): bool
    {
        $role = $this->role($board, $actor);
        if ($role === null || self::RANK[$role] < self::RANK[$need]) {
            return false;
        }

        return $need === 'viewer' || ! $board->isReadOnly();
    }

    public function assert(Board $board, User $actor, string $need): void
    {
        if (! $this->can($board, $actor, $need)) {
            throw $this->role($board, $actor) === null ? new \Illuminate\Database\Eloquent\ModelNotFoundException() : new PermissionDeniedException();
        }
    }

    /** Reopening a completed or archived board: managers only. */
    public function canManageClosed(Board $board, User $actor): bool
    {
        return $this->role($board, $actor) === 'manager';
    }

    /** Whether this person may start a board, and say why not. */
    public function createRefusal(User $actor, Settings $settings): ?string
    {
        if ($actor->isGuest() || ! $actor->hasPermission('corkboard.create')) {
            return 'permission';
        }
        $min = $settings->minPosts();
        if ($min > 0 && (int) $actor->comment_count < $min && ! $actor->hasPermission('corkboard.moderate')) {
            return 'posts';
        }

        return null;
    }

    /** @return array<string, bool> */
    public function abilities(Board $board, User $actor): array
    {
        $role = $this->role($board, $actor);

        return [
            'comment' => $this->can($board, $actor, 'commenter'),
            'edit' => $this->can($board, $actor, 'editor'),
            'manage' => $this->can($board, $actor, 'manager'),
            'reopen' => $board->isReadOnly() && $role === 'manager',
            'template' => $actor->hasPermission('corkboard.moderate'),
            'public' => $actor->hasPermission('corkboard.createPublic'),
        ];
    }
}
