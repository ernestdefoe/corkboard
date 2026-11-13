<?php

namespace ErnestDefoe\Corkboard\Api;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Boards;
use ErnestDefoe\Corkboard\Column;
use ErnestDefoe\Corkboard\Presenter;
use ErnestDefoe\Corkboard\Projects;
use ErnestDefoe\Corkboard\Push;
use ErnestDefoe\Corkboard\Templates;
use Flarum\Group\Group;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET    /corkboard/boards?tab=mine|all|completed|archived&q=&page=&project=   the boards list
 * POST   /corkboard/boards                                            start one (title, template, visibility…)
 * GET    /corkboard/boards/{id}                                       the whole board (Presenter::board)
 * PATCH  /corkboard/boards/{id}                                       settings, status, owner
 * DELETE /corkboard/boards/{id}
 * POST   /corkboard/boards/{id}/clone                                 title, cards, due ('clear' | days)
 * GET    /corkboard/boards/{id}/version                               the poll: {version}
 * GET    /corkboard/boards/{id}/cards?column=&after= | ?archived=1    more of a big column, or the archived cards
 * POST   /corkboard/boards/{id}/pin                                   pinned: bool (the viewer's own list)
 * POST   /corkboard/boards/{id}/moving                                cardId|null: "Ana is moving this"
 * GET    /corkboard/templates
 */
class BoardsController extends Controller
{
    public const PAGE = 30;

    public function __construct(
        private readonly Boards $boards,
        private readonly Presenter $presenter,
        private readonly Access $access,
        private readonly ConnectionInterface $db,
        private readonly TranslatorInterface $translator,
        private readonly Push $push,
    ) {
    }

    /** @return array<string, mixed> */
    protected function index(ServerRequestInterface $request, User $actor): array
    {
        $q = $request->getQueryParams();
        $tab = in_array($q['tab'] ?? '', ['mine', 'all', 'completed', 'archived', 'templates'], true) ? $q['tab'] : ($actor->isGuest() ? 'all' : 'mine');
        $page = max(0, (int) ($q['page'] ?? 0));

        $query = Board::whereVisibleTo($actor)->select('corkboard_boards.*');
        match ($tab) {
            'completed' => $query->where('corkboard_boards.status', 'completed'),
            'archived' => $query->where('corkboard_boards.status', 'archived'),
            'templates' => $query->where('corkboard_boards.is_template', true),
            default => $query->where('corkboard_boards.status', 'active'),
        };
        if ($tab === 'mine') {
            $this->mine($query, $actor);
        }
        // A project's boards (the Projects page shows them).
        if (! empty($q['project'])) {
            $query->where('corkboard_boards.project_id', (int) $q['project']);
        }
        $text = trim((string) ($q['q'] ?? ''));
        if ($text !== '') {
            $query->where('corkboard_boards.title', 'like', '%'.addcslashes($text, '%_\\').'%');
        }

        $pins = $actor->isGuest() ? collect() : $this->db->table('corkboard_pins')->where('user_id', $actor->id)->pluck('position', 'board_id');
        $boards = $query->orderByDesc('corkboard_boards.last_activity_at')->orderByDesc('corkboard_boards.id')
            ->offset($page * self::PAGE)->limit(self::PAGE + 1)->get();
        $more = $boards->count() > self::PAGE;
        $boards = $boards->take(self::PAGE);
        $this->access->prime($boards, $actor);

        // Pinned boards the viewer can still see, for the row on top (first page only).
        $pinnedBoards = collect();
        if ($page === 0 && $pins->isNotEmpty() && in_array($tab, ['mine', 'all'], true) && $text === '') {
            $pinnedBoards = Board::whereVisibleTo($actor)->whereIn('corkboard_boards.id', $pins->keys())->where('corkboard_boards.status', 'active')->get()
                ->sortBy(fn ($b) => $pins[$b->id])->values();
            $this->access->prime($pinnedBoards, $actor);
        }

        // Team pictures and "due this week" for every tile on the page, in one query each.
        $ids = $boards->pluck('id')->merge($pinnedBoards->pluck('id'))->unique()->values()->all();
        $members = $ids ? $this->db->table('corkboard_members')->whereIn('board_id', $ids)->whereNotNull('user_id')->orderBy('id')->get(['board_id', 'user_id'])->groupBy('board_id') : collect();
        $due = $ids ? $this->db->table('corkboard_cards')->whereIn('board_id', $ids)->whereNull('archived_at')->whereNull('completed_at')
            ->whereNotNull('due_at')->where('due_at', '<=', Carbon::now()->addDays(7))
            ->groupBy('board_id')->selectRaw('board_id, count(*) as n')->pluck('n', 'board_id') : collect();

        $userIds = [];
        $tile = function (Board $board) use ($members, $due, $pins, $actor, &$userIds): array {
            $people = array_values(array_unique(array_merge(array_filter([$board->owner_id]), ($members[$board->id] ?? collect())->pluck('user_id')->map(fn ($id) => (int) $id)->all())));
            $people = array_slice($people, 0, 5);
            array_push($userIds, ...$people);

            return $this->presenter->boardSummary($board) + [
                'description' => $board->description,
                'role' => $this->access->role($board, $actor),
                'people' => $people,
                'teamSize' => count($members[$board->id] ?? []) + ($board->owner_id ? 1 : 0),
                'dueSoon' => (int) ($due[$board->id] ?? 0),
                'pinned' => isset($pins[$board->id]),
            ];
        };
        $items = $boards->map($tile)->values()->all();
        $pinned = $pinnedBoards->map($tile)->values()->all();

        return [
            'tab' => $tab,
            'boards' => $items,
            'pinned' => $pinned,
            'more' => $more,
            'users' => $this->presenter->users($userIds),
        ];
    }

    /** Boards the viewer owns or is on the team of (by name, or through a group other than Members). */
    private function mine(Builder $query, User $actor): void
    {
        if ($actor->isGuest()) {
            $query->whereRaw('1 = 0');

            return;
        }
        $groups = array_values(array_diff(Access::groupIds($actor), [Group::GUEST_ID, Group::MEMBER_ID]));
        $query->where(fn (Builder $q) => $q->where('corkboard_boards.owner_id', $actor->id)
            ->orWhereIn('corkboard_boards.id', fn ($m) => $m->select('board_id')->from('corkboard_members')
                ->where('user_id', $actor->id)
                ->when($groups, fn ($g) => $g->orWhereIn('group_id', $groups))));
    }

    /** @return array<string, mixed> */
    protected function create(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->boards->create($actor, $this->body($request));

        return $this->presenter->board($board->fresh() ?? $board, $actor);
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);
        $out = $this->presenter->board($board, $actor);
        if (! $actor->isGuest()) {
            $out['pinned'] = $this->db->table('corkboard_pins')->where('user_id', $actor->id)->where('board_id', $board->id)->exists();
            if ($this->access->can($board, $actor, 'manager')) {
                $out['projects'] = Projects::mine($this->db, $actor);
            }
        }
        $out['push'] = Push::available(resolve('container'));

        return $out;
    }

    /** @return array<string, mixed> */
    protected function update(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->boards->update($this->board($request, $actor), $actor, $this->body($request));

        return $this->presenter->board($board, $actor);
    }

    protected function delete(ServerRequestInterface $request, User $actor): null
    {
        $this->boards->delete($this->board($request, $actor), $actor);

        return null;
    }

    /** @return array<string, mixed> */
    protected function clone(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->boards->clone($this->board($request, $actor), $actor, $this->body($request));

        return ['id' => $board->id, 'slug' => $board->slug];
    }

    /** @return array<string, int> */
    protected function version(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);

        return ['version' => (int) $board->version];
    }

    /** @return array<string, mixed> */
    protected function cards(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);
        $q = $request->getQueryParams();
        if (! empty($q['archived'])) {
            $rows = $this->db->table('corkboard_cards')->where('board_id', $board->id)->whereNotNull('archived_at')
                ->orderByDesc('archived_at')->limit(100)
                ->get(['id', 'column_id', 'position', 'title', 'priority', 'due_at', 'due_all_day', 'completed_at', 'user_id', 'discussion_id', 'cover_color', 'comment_count', 'checklist_total', 'checklist_done']);
        } else {
            $column = Column::query()->where('board_id', $board->id)->findOrFail((int) ($q['column'] ?? 0));
            $rows = $this->presenter->openCards($board->id, null, $column->id, isset($q['after']) ? (int) $q['after'] : null);
        }
        [$cards, $users] = $this->presenter->slim($rows);

        return ['cards' => $cards, 'users' => $this->presenter->users($users), 'more' => $rows->count() >= Presenter::SLICE];
    }

    /** @return array<string, bool> */
    protected function pin(ServerRequestInterface $request, User $actor): array
    {
        $actor->assertRegistered();
        $board = $this->board($request, $actor);
        $pinned = (bool) ($this->body($request)['pinned'] ?? true);
        if ($pinned) {
            $position = (int) $this->db->table('corkboard_pins')->where('user_id', $actor->id)->max('position') + 1;
            $this->db->table('corkboard_pins')->updateOrInsert(['user_id' => $actor->id, 'board_id' => $board->id], ['position' => $position]);
        } else {
            $this->db->table('corkboard_pins')->where('user_id', $actor->id)->where('board_id', $board->id)->delete();
        }

        return ['pinned' => $pinned];
    }

    protected function moving(ServerRequestInterface $request, User $actor): null
    {
        $board = $this->board($request, $actor);
        if ($this->access->can($board, $actor, 'editor')) {
            $card = $this->body($request)['cardId'] ?? null;
            $this->push->moving($board, $actor, $card ? (int) $card : null);
        }

        return null;
    }

    /** @return array<string, mixed> */
    protected function templates(ServerRequestInterface $request, User $actor): array
    {
        $t = fn (string $key) => $this->translator->trans($key);
        $builtIn = [];
        foreach (array_keys(Templates::BUILT_IN) as $key) {
            $builtIn[] = [
                'key' => $key,
                'title' => $t('ernestdefoe-corkboard.lib.template.'.$key),
                'columns' => array_column(Templates::columns($key, $t), 'title'),
            ];
        }
        $custom = [];
        if ($actor->hasPermission('corkboard.useTemplates')) {
            $boards = Board::query()->where('is_template', true)->where('status', 'active')->orderBy('title')->limit(30)->get(['id', 'title', 'color']);
            $columns = $boards->isEmpty() ? collect() : Column::query()->whereIn('board_id', $boards->pluck('id'))->orderBy('position')->get(['board_id', 'title'])->groupBy('board_id');
            foreach ($boards as $board) {
                $custom[] = ['key' => (string) $board->id, 'title' => $board->title, 'color' => $board->color, 'columns' => ($columns[$board->id] ?? collect())->pluck('title')->all()];
            }
        }

        return ['builtIn' => $builtIn, 'custom' => $custom];
    }
}
