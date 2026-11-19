<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Api\Client;
use Flarum\Formatter\Formatter;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Everything the browser reads, shaped once.
 *
 * A board is one answer: the board, its columns, labels and team, and every open card in a
 * slim form (no description, no checklist text, no comments: those come when a card opens).
 * The query count doesn't grow with the number of cards (tests/…/a_board_costs_the_same_queries…).
 */
class Presenter
{
    /** Above this many open cards a board sends each column's first SLICE and the rest on request. */
    public const BIG = 500;
    public const SLICE = 150;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Formatter $formatter,
        private readonly Extensions $extensions,
        private readonly Projects $projects,
        private readonly Client $api,
    ) {
    }

    /** @return array<string, mixed> */
    public function board(Board $board, User $actor): array
    {
        $columns = Column::query()->where('board_id', $board->id)->orderBy('position')->orderBy('id')->get();
        $labels = Label::query()->where('board_id', $board->id)->orderBy('position')->orderBy('id')->get();
        $members = Member::query()->where('board_id', $board->id)->orderBy('id')->get();

        $big = $board->card_count > self::BIG;
        $cards = $this->openCards($board->id, $big ? $columns->pluck('id')->all() : null);
        [$slim, $userIds] = $this->slim($cards);
        $userIds = array_merge($userIds, $members->pluck('user_id')->filter()->all(), [$board->owner_id]);

        $more = [];
        if ($big) {
            // Open cards per column in one grouped count, for the "N more" of a big column.
            $counts = $this->db->table('corkboard_cards')->where('board_id', $board->id)->whereNull('archived_at')
                ->groupBy('column_id')->select('column_id')->selectRaw('count(*) as n')->pluck('n', 'column_id');
            foreach ($columns as $column) {
                $shown = count(array_filter($slim, fn ($c) => $c['columnId'] === $column->id));
                $more[$column->id] = max(0, (int) ($counts[$column->id] ?? 0) - $shown);
            }
        }

        $groupIds = $members->pluck('group_id')->filter()->all();

        return [
            'board' => $this->boardSummary($board) + [
                'description' => $board->description,
                'tagId' => $board->tag_id,
                'tag' => $board->tag_id ? $this->tag($board->tag_id, $actor) : null,
                'projectId' => $board->project_id,
                'project' => $board->project_id ? $this->project($board->project_id, $actor) : null,
            ],
            'role' => $this->access->role($board, $actor),
            'abilities' => $this->access->abilities($board, $actor),
            'columns' => $columns->map(fn (Column $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'color' => $c->color,
                'wipLimit' => $c->wip_limit,
                'isDone' => $c->is_done,
                'autoArchiveDays' => $c->auto_archive_days,
                'more' => $more[$c->id] ?? 0,
            ])->values()->all(),
            'labels' => $labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values()->all(),
            'members' => $members->map(fn (Member $m) => ['id' => $m->id, 'userId' => $m->user_id, 'groupId' => $m->group_id, 'role' => $m->role])->values()->all(),
            'cards' => $slim,
            'users' => $this->users($userIds),
            'groups' => $groupIds ? $this->groups($groupIds, $actor) : (object) [],
        ];
    }

    /** @return array<string, mixed> what a board's tile on the list shows */
    public function boardSummary(Board $board): array
    {
        return [
            'id' => $board->id,
            'slug' => $board->slug,
            'title' => $board->title,
            'color' => $board->color,
            'visibility' => $board->visibility,
            'status' => $board->status,
            'isTemplate' => $board->is_template,
            'ownerId' => $board->owner_id,
            'cardCount' => $board->card_count,
            'doneCount' => $board->done_count,
            'version' => $board->version,
            'completedAt' => $board->completed_at?->toIso8601String(),
            'archivedAt' => $board->archived_at?->toIso8601String(),
            'lastActivityAt' => ($board->last_activity_at ?? $board->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @param list<int>|null $sliceColumns when set, only each column's first SLICE cards
     * @return Collection<int, \stdClass>
     */
    public function openCards(int $boardId, ?array $sliceColumns = null, ?int $column = null, ?int $afterPosition = null): Collection
    {
        $base = fn () => $this->db->table('corkboard_cards')
            ->where('board_id', $boardId)->whereNull('archived_at')
            ->select(['id', 'column_id', 'position', 'title', 'priority', 'due_at', 'due_all_day', 'completed_at', 'user_id', 'discussion_id', 'cover_color', 'comment_count', 'checklist_total', 'checklist_done']);

        if ($column !== null) {
            return $base()->where('column_id', $column)->when($afterPosition !== null, fn ($q) => $q->where('position', '>', $afterPosition))
                ->orderBy('position')->orderBy('id')->limit(self::SLICE)->get();
        }
        if ($sliceColumns === null) {
            return $base()->orderBy('position')->orderBy('id')->get();
        }

        // One query: a union of each column's first slice.
        $query = null;
        foreach ($sliceColumns as $id) {
            $part = $base()->where('column_id', $id)->orderBy('position')->orderBy('id')->limit(self::SLICE);
            $query = $query ? $query->unionAll($this->db->query()->fromSub($part, 'p')->select('*')) : $this->db->query()->fromSub($part, 'p')->select('*');
        }

        return $query ? $query->get() : collect();
    }

    /**
     * Cards as the board draws them, with their labels and assignees (one query each).
     *
     * @param Collection<int, \stdClass> $cards
     * @return array{list<array<string, mixed>>, list<int>}
     */
    public function slim(Collection $cards): array
    {
        $ids = $cards->pluck('id')->map(fn ($id) => (int) $id)->all();
        $labels = $assignees = [];
        if ($ids) {
            foreach ($this->db->table('corkboard_card_label')->whereIn('card_id', $ids)->get() as $row) {
                $labels[(int) $row->card_id][] = (int) $row->label_id;
            }
            foreach ($this->db->table('corkboard_assignees')->whereIn('card_id', $ids)->orderBy('created_at')->get() as $row) {
                $assignees[(int) $row->card_id][] = (int) $row->user_id;
            }
        }

        $users = [];
        $out = [];
        foreach ($cards as $c) {
            $id = (int) $c->id;
            $people = $assignees[$id] ?? [];
            array_push($users, ...$people);
            $out[] = [
                'id' => $id,
                'columnId' => (int) $c->column_id,
                'position' => (int) $c->position,
                'title' => $c->title,
                'priority' => $c->priority,
                'dueAt' => self::iso($c->due_at),
                'dueAllDay' => (bool) $c->due_all_day,
                'completedAt' => self::iso($c->completed_at),
                'ownerId' => $c->user_id === null ? null : (int) $c->user_id,
                'discussionId' => $c->discussion_id === null ? null : (int) $c->discussion_id,
                'coverColor' => $c->cover_color,
                'commentCount' => (int) $c->comment_count,
                'checklistTotal' => (int) $c->checklist_total,
                'checklistDone' => (int) $c->checklist_done,
                'labelIds' => $labels[$id] ?? [],
                'assigneeIds' => $people,
            ];
        }

        return [$out, $users];
    }

    /** @return array<string, mixed> a written card as the board draws it, and its board's version and counts */
    public function written(Card $card): array
    {
        [$slim, $users] = $this->slim(collect([(object) $card->getAttributes()]));

        return [
            'card' => $slim[0],
            'users' => $this->users($users),
            'version' => (int) $card->board->version,
            'cardCount' => $card->board->card_count,
            'doneCount' => $card->board->done_count,
        ];
    }

    /** @return array<string, mixed> everything a card shows when it opens */
    public function card(Card $card, User $actor, ServerRequestInterface $request): array
    {
        $board = $card->board;
        [$slim, $users] = $this->slim(collect([(object) $card->getAttributes()]));
        $comments = $this->comments($card, $actor, $request, null);
        $checklist = ChecklistItem::query()->where('card_id', $card->id)->orderBy('position')->orderBy('id')->get();
        $users = array_merge($users, [$card->user_id, $card->completed_by], $comments['userIds'], $checklist->pluck('user_id')->filter()->all());

        $watch = $actor->isGuest() ? null : $this->db->table('corkboard_watchers')->where('card_id', $card->id)->where('user_id', $actor->id)->value('watching');
        $watchers = $this->db->table('corkboard_watchers')->where('card_id', $card->id)->where('watching', true)->count();

        $canEdit = $this->access->can($board, $actor, 'editor');

        return $slim[0] + [
            'boardId' => $board->id,
            'archivedAt' => $card->archived_at?->toIso8601String(),
            'completedBy' => $card->completed_by,
            'createdAt' => $card->created_at?->toIso8601String(),
            'descriptionHtml' => $card->description ? $this->formatter->render($card->description, null, $request) : null,
            // The text as typed, for the editor.
            'description' => $canEdit && $card->description ? $this->formatter->unparse($card->description, null) : null,
            'checklist' => $checklist->map(fn (ChecklistItem $i) => ['id' => $i->id, 'text' => $i->text, 'isDone' => $i->is_done, 'userId' => $i->user_id])->values()->all(),
            'comments' => $comments['items'],
            'commentsMore' => $comments['more'],
            'watching' => $watch === null ? null : (bool) $watch,
            'watcherCount' => $watchers,
            'discussion' => $card->discussion_id ? $this->discussion($card->discussion_id, $actor, $request) : null,
            'users' => $this->users($users),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, more: bool, userIds: list<int>} newest first, 20 at a time */
    public function comments(Card $card, User $actor, ServerRequestInterface $request, ?int $before): array
    {
        $rows = Comment::query()->where('card_id', $card->id)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(21)->get();
        $more = $rows->count() > 20;
        $rows = $rows->take(20);

        return [
            'items' => $rows->map(fn (Comment $c) => $this->comment($c, $card->board, $actor, $request))->values()->all(),
            'more' => $more,
            'userIds' => $rows->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * One comment as the card shows it, and what this viewer may do with it: the one place both
     * the card's first page and the corkboard-comments resource build it.
     *
     * @return array{id: int, userId: int|null, html: string, source: string|null, createdAt: string|null, editedAt: string|null, canEdit: bool, canDelete: bool}
     */
    public function comment(Comment $comment, Board $board, User $actor, ServerRequestInterface $request): array
    {
        $mine = ! $actor->isGuest() && $comment->user_id === $actor->id;
        $open = ! $board->isReadOnly();

        return [
            'id' => $comment->id,
            'userId' => $comment->user_id,
            'html' => $this->formatter->render($comment->content, null, $request),
            // The text as typed, for the author's editor.
            'source' => $mine ? $this->formatter->unparse($comment->content, null) : null,
            'createdAt' => $comment->created_at?->toIso8601String(),
            'editedAt' => $comment->edited_at?->toIso8601String(),
            'canEdit' => $mine && $open,
            'canDelete' => $open && ($mine || $this->access->can($board, $actor, 'manager')),
        ];
    }

    /**
     * The linked discussion and its last three replies, if this viewer may see it, read the way the
     * forum itself reads them: one request to core's posts endpoint through Flarum's internal API
     * client, as the viewer (their session too). That runs every extension's API middleware and
     * field changes, so whatever hides or rewrites a title or a post for this person (a hidden or
     * private discussion, a post they can't see, Spoiler Shield's gate) has already happened to
     * what comes back. The last four comment posts come with their discussion and authors; the
     * first post isn't a reply. A fixed number of queries, whatever the discussion's size.
     *
     * @return array<string, mixed>|null
     */
    public function discussion(int $id, User $actor, ServerRequestInterface $request): ?array
    {
        $response = $this->api->withActor($actor)->withParentRequest($request)
            ->withQueryParams([
                'filter' => ['discussion' => (string) $id, 'type' => 'comment'],
                'sort' => '-number',
                'page' => ['limit' => 4],
                'include' => 'user,discussion',
            ])
            ->get('/posts');
        $doc = $response->getStatusCode() === 200 ? json_decode((string) $response->getBody(), true) : null;
        $included = [];
        foreach ((array) ($doc['included'] ?? []) as $resource) {
            $included[$resource['type'].':'.$resource['id']] = $resource['attributes'] ?? [];
        }
        $discussion = $included['discussions:'.$id] ?? null;
        if ($discussion === null) {
            return null;
        }
        $replies = array_reverse(array_slice(array_filter((array) $doc['data'], fn (array $post) => ($post['attributes']['number'] ?? 0) > 1), 0, 3));

        return [
            'id' => $id,
            'title' => $discussion['title'] ?? null,
            'slug' => $discussion['slug'] ?? null,
            'replyCount' => max(0, (int) ($discussion['commentCount'] ?? 1) - 1),
            'lastReplies' => array_map(function (array $post) use ($included) {
                $author = $included['users:'.($post['relationships']['user']['data']['id'] ?? '')] ?? null;

                return [
                    'id' => (int) $post['id'],
                    'number' => (int) $post['attributes']['number'],
                    'createdAt' => $post['attributes']['createdAt'] ?? null,
                    'user' => $author ? [
                        'id' => (int) $post['relationships']['user']['data']['id'],
                        'name' => $author['displayName'] ?? null,
                        'username' => $author['username'] ?? null,
                        'slug' => $author['slug'] ?? null,
                        'avatarUrl' => $author['avatarUrl'] ?? null,
                    ] : null,
                    // An allow-list excerpt of the contentHtml as the API gave it to this viewer.
                    'excerpt' => Excerpt::make(is_string($post['attributes']['contentHtml'] ?? null) ? $post['attributes']['contentHtml'] : null, 160),
                ];
            }, $replies),
        ];
    }

    /**
     * Keyed by id (never 0, so JSON always makes it an object, not a list).
     *
     * @param array<int|null> $ids
     * @return array<int, array<string, mixed>>|object
     */
    public function users(array $ids): array|object
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return (object) [];
        }
        $out = [];
        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            $out[(int) $user->id] = $this->user($user);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->display_name,
            'username' => $user->username,
            'slug' => $user->slug ?? $user->username,
            'avatarUrl' => $user->avatar_url,
        ];
    }

    /**
     * A team's groups. A hidden group's name, look and size are only for moderators (and whoever
     * may see hidden groups): everyone else gets the row with no name ("A hidden group").
     *
     * @param array<int|null> $ids
     * @return array<int, array<string, mixed>>
     */
    public function groups(array $ids, User $actor): array
    {
        $out = [];
        $seesHidden = $actor->hasPermission('corkboard.moderate') || $actor->hasPermission('viewHiddenGroups');
        // Members per group in one grouped count.
        $counts = $this->db->table('group_user')->whereIn('group_id', $ids)->groupBy('group_id')->select('group_id')->selectRaw('count(*) as n')->pluck('n', 'group_id');
        foreach (Group::query()->whereIn('id', $ids)->get() as $group) {
            if ($group->is_hidden && ! $seesHidden) {
                $out[(int) $group->id] = ['id' => $group->id, 'name' => null, 'color' => null, 'icon' => null, 'count' => null, 'hidden' => true];
                continue;
            }
            $out[(int) $group->id] = [
                'id' => $group->id,
                'name' => $group->name_plural,
                'color' => $group->color,
                'icon' => $group->icon,
                // Members and Guests aren't stored per person: no count.
                'count' => in_array((int) $group->id, [Group::GUEST_ID, Group::MEMBER_ID], true) ? null : (int) ($counts[$group->id] ?? 0),
                'hidden' => (bool) $group->is_hidden,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function tag(int $id, User $actor): ?array
    {
        if (! $this->extensions->tags()) {
            return null;
        }
        // A restricted tag's name is only for people who may see the tag.
        $tag = \Flarum\Tags\Tag::whereVisibleTo($actor)->whereKey($id)->first(['id', 'name', 'slug', 'color']);

        return $tag ? ['id' => (int) $tag->id, 'name' => $tag->name, 'slug' => $tag->slug, 'color' => $tag->color] : null;
    }

    /** @return array<string, mixed>|null */
    private function project(int $id, User $actor): ?array
    {
        return $this->projects->summary($id, $actor);
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof Carbon ? $value : Carbon::parse((string) $value, 'UTC'))->toIso8601String();
    }
}
