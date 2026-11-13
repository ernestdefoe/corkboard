<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/** Starting, changing, cloning, completing and deleting boards. */
class Boards
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Settings $settings,
        private readonly Log $log,
        private readonly Notify $notify,
        private readonly Counters $counters,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $in */
    public function create(User $actor, array $in): Board
    {
        if ($why = $this->access->createRefusal($actor, $this->settings)) {
            throw $why === 'posts' ? Refusal::because('min_posts') : new PermissionDeniedException();
        }

        $template = (string) ($in['template'] ?? 'simple');
        $source = null;
        if (ctype_digit($template)) {
            $source = Board::query()->where('is_template', true)->find((int) $template);
            if (! $source || ! $actor->hasPermission('corkboard.useTemplates')) {
                throw Refusal::because('template');
            }
        } elseif (! isset(Templates::BUILT_IN[$template])) {
            $template = 'simple';
        }

        return $this->db->transaction(function () use ($actor, $in, $template, $source) {
            $board = new Board();
            $board->owner_id = $actor->id;
            $board->status = 'active';
            $board->last_activity_at = Carbon::now();
            $this->fill($board, $actor, $in + ['visibility' => $this->settings->defaultVisibility()], true);
            $board->save();

            if ($source) {
                $this->copy($source, $board, ['cards' => true, 'due' => 'clear']);
            } else {
                $now = Carbon::now();
                $columns = [];
                foreach (Templates::columns($template, fn ($k) => $this->translator->trans($k)) as $i => $column) {
                    $columns[] = ['board_id' => $board->id, 'title' => $column['title'], 'is_done' => $column['isDone'], 'position' => ($i + 1) * Positions::GAP, 'created_at' => $now, 'updated_at' => $now];
                }
                $this->db->table('corkboard_columns')->insert($columns);
                $labels = [];
                foreach (Label::DEFAULTS as $i => $color) {
                    $labels[] = ['board_id' => $board->id, 'name' => '', 'color' => $color, 'position' => $i];
                }
                $this->db->table('corkboard_labels')->insert($labels);
            }
            $this->log->record($board, null, $actor, 'board_created');

            return $board;
        });
    }

    /** @param array<string, mixed> $in */
    public function update(Board $board, User $actor, array $in): Board
    {
        // Reopening is the one change a closed board takes.
        if (isset($in['status'])) {
            $this->status($board, $actor, (string) $in['status']);
            unset($in['status']);
        }
        if (! $in) {
            return $board;
        }
        $this->access->assert($board, $actor, 'manager');

        if (array_key_exists('ownerId', $in)) {
            $this->transfer($board, $actor, (int) $in['ownerId']);
        }

        $before = $board->visibility;
        $this->fill($board, $actor, $in, false);
        $board->save();

        if ($before !== $board->visibility && $board->visibility === 'private') {
            // Private boards take people by name only.
            $this->db->table('corkboard_members')->where('board_id', $board->id)->whereNotNull('group_id')->delete();
        }
        $this->access->forget($board, $actor);
        $this->log->record($board, null, $actor, 'board_updated', ['f' => array_keys($in)]);

        return $board;
    }

    private function status(Board $board, User $actor, string $status): void
    {
        if (! in_array($status, Board::STATUSES, true) || $status === $board->status) {
            return;
        }
        if (! $this->access->canManageClosed($board, $actor)) {
            throw new PermissionDeniedException();
        }
        $board->status = $status;
        $board->completed_at = $status === 'completed' ? Carbon::now() : null;
        $board->archived_at = $status === 'archived' ? Carbon::now() : null;
        $board->save();
        $ref = $this->log->record($board, null, $actor, 'board_'.($status === 'active' ? 'reopened' : $status));

        if ($status === 'completed') {
            $this->notify->send(Notification\CompletedBlueprint::class, $board, $actor, $this->teamUserIds($board), $ref);
        }
    }

    private function transfer(Board $board, User $actor, int $to): void
    {
        if ((int) $board->owner_id !== (int) $actor->id && ! $actor->hasPermission('corkboard.moderate')) {
            throw new PermissionDeniedException();
        }
        // The new owner must be on the team already, or able to see the board.
        $user = User::query()->find($to);
        $onTeam = $user && $this->db->table('corkboard_members')->where('board_id', $board->id)->where('user_id', $user->id)->exists();
        if (! $user || (! $onTeam && ! Board::whereVisibleTo($user)->whereKey($board->id)->exists())) {
            throw Refusal::because('owner');
        }
        $old = $board->owner_id;
        $board->owner_id = $user->id;
        // The old owner stays on as a manager; the new one needs no team row.
        $this->db->table('corkboard_members')->where('board_id', $board->id)->where('user_id', $user->id)->delete();
        if ($old && ! $this->db->table('corkboard_members')->where('board_id', $board->id)->where('user_id', $old)->exists()) {
            $this->db->table('corkboard_members')->insert(['board_id' => $board->id, 'user_id' => $old, 'role' => 'manager', 'created_at' => Carbon::now()]);
        }
    }

    /** @param array<string, mixed> $in */
    private function fill(Board $board, User $actor, array $in, bool $creating): void
    {
        if ($creating || array_key_exists('title', $in)) {
            $title = trim(preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')) ?? '');
            if ($title === '' || mb_strlen($title) > 160) {
                throw Refusal::because('title', 'title');
            }
            $board->title = $title;
            $board->slug = Str::limit(Str::slug($title), 150, '') ?: '';
        }
        if (array_key_exists('description', $in)) {
            $text = trim((string) $in['description']);
            $board->description = $text === '' ? null : mb_substr($text, 0, 1000);
        }
        if (array_key_exists('color', $in) || $creating) {
            $color = (string) ($in['color'] ?? '#4d698e');
            $board->color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#4d698e';
        }
        if (array_key_exists('visibility', $in)) {
            $v = (string) $in['visibility'];
            if (! in_array($v, $this->settings->visibilities(), true) && $v !== $board->visibility) {
                throw Refusal::because('visibility', 'visibility');
            }
            if ($v === 'public' && $board->visibility !== 'public' && ! $actor->hasPermission('corkboard.createPublic')) {
                throw Refusal::because('public', 'visibility');
            }
            $board->visibility = $v;
        }
        if (array_key_exists('tagId', $in)) {
            $id = $in['tagId'] === null || $in['tagId'] === '' ? null : (int) $in['tagId'];
            if ($id !== null && (! resolve(Extensions::class)->tags() || ! \Flarum\Tags\Tag::whereVisibleTo($actor)->whereKey($id)->exists())) {
                throw Refusal::because('tag', 'tagId');
            }
            $board->tag_id = $id;
        }
        if (array_key_exists('projectId', $in)) {
            $id = $in['projectId'] === null || $in['projectId'] === '' ? null : (int) $in['projectId'];
            if ($id !== null && (! Projects::enabled() || (! Projects::isAuthor($this->db, $id, $actor) && ! $actor->hasPermission('corkboard.moderate')))) {
                throw Refusal::because('project', 'projectId');
            }
            $board->project_id = $id;
        }
        if (array_key_exists('isTemplate', $in)) {
            if (! $actor->hasPermission('corkboard.moderate')) {
                throw new PermissionDeniedException();
            }
            $board->is_template = (bool) $in['isTemplate'];
        }
    }

    /**
     * A copy of a board: columns and labels, and optionally its cards with their descriptions,
     * checklists and labels. Never assignees, comments or history. Due dates cleared or shifted.
     *
     * @param array<string, mixed> $in title, cards (bool), due ('clear' | number of days)
     */
    public function clone(Board $source, User $actor, array $in): Board
    {
        $this->access->assert($source, $actor, 'viewer');
        if ($why = $this->access->createRefusal($actor, $this->settings)) {
            throw $why === 'posts' ? Refusal::because('min_posts') : new PermissionDeniedException();
        }

        return $this->db->transaction(function () use ($source, $actor, $in) {
            $board = new Board();
            $board->owner_id = $actor->id;
            $board->status = 'active';
            $board->last_activity_at = Carbon::now();
            $visibility = $source->visibility === 'public' && ! $actor->hasPermission('corkboard.createPublic') ? 'team' : $source->visibility;
            $this->fill($board, $actor, [
                'title' => $in['title'] ?? $this->translator->trans('ernestdefoe-corkboard.lib.board.copy_title', ['{title}' => $source->title]),
                'description' => $source->description,
                'color' => $source->color,
                'visibility' => $in['visibility'] ?? $visibility,
            ], true);
            $board->tag_id = $source->tag_id;
            $board->save();
            $this->copy($source, $board, ['cards' => (bool) ($in['cards'] ?? true), 'due' => $in['due'] ?? 'clear']);
            $this->log->record($board, null, $actor, 'board_cloned', ['from' => $source->id]);

            return $board;
        });
    }

    /** @param array<string, mixed> $opts */
    private function copy(Board $from, Board $to, array $opts): void
    {
        // Inserted together, then matched back to the originals by order (one query each way).
        $now = Carbon::now();
        $labels = Label::query()->where('board_id', $from->id)->orderBy('position')->orderBy('id')->get();
        $labels->isNotEmpty() && $this->db->table('corkboard_labels')->insert($labels->map(fn (Label $l) => ['board_id' => $to->id, 'name' => $l->name, 'color' => $l->color, 'position' => $l->position])->all());
        $labelMap = $labels->isEmpty() ? [] : array_combine($labels->pluck('id')->all(), $this->db->table('corkboard_labels')->where('board_id', $to->id)->orderBy('position')->orderBy('id')->pluck('id')->all());
        $columns = Column::query()->where('board_id', $from->id)->orderBy('position')->orderBy('id')->get();
        $this->db->table('corkboard_columns')->insert($columns->map(fn (Column $c) => [
            'board_id' => $to->id, 'title' => $c->title, 'color' => $c->color, 'position' => $c->position,
            'wip_limit' => $c->wip_limit, 'is_done' => $c->is_done, 'auto_archive_days' => $c->auto_archive_days, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
        $columnMap = array_combine($columns->pluck('id')->all(), $this->db->table('corkboard_columns')->where('board_id', $to->id)->orderBy('position')->orderBy('id')->pluck('id')->all());
        if (empty($opts['cards'])) {
            return;
        }
        $shift = is_numeric($opts['due'] ?? null) ? (int) $opts['due'] : null;
        $cards = Card::query()->where('board_id', $from->id)->whereNull('archived_at')->whereNull('completed_at')->orderBy('position')->get();
        foreach ($cards->chunk(100) as $chunk) {
            $labels = $this->db->table('corkboard_card_label')->whereIn('card_id', $chunk->pluck('id'))->get()->groupBy('card_id');
            $items = $this->db->table('corkboard_checklist')->whereIn('card_id', $chunk->pluck('id'))->orderBy('position')->get()->groupBy('card_id');
            foreach ($chunk as $card) {
                $copy = Card::query()->create([
                    'board_id' => $to->id, 'column_id' => $columnMap[$card->column_id], 'position' => $card->position,
                    'title' => $card->title, 'description' => $card->description, 'priority' => $card->priority,
                    'due_at' => $shift !== null && $card->due_at ? $card->due_at->copy()->addDays($shift) : null,
                    'due_all_day' => $card->due_all_day, 'user_id' => $to->owner_id, 'cover_color' => $card->cover_color,
                    'checklist_total' => $card->checklist_total,
                ]);
                $pivot = [];
                foreach ($labels[$card->id] ?? [] as $row) {
                    if (isset($labelMap[$row->label_id])) {
                        $pivot[] = ['card_id' => $copy->id, 'label_id' => $labelMap[$row->label_id]];
                    }
                }
                $pivot && $this->db->table('corkboard_card_label')->insert($pivot);
                $rows = [];
                foreach ($items[$card->id] ?? [] as $item) {
                    $rows[] = ['card_id' => $copy->id, 'text' => $item->text, 'is_done' => false, 'position' => $item->position, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()];
                }
                $rows && $this->db->table('corkboard_checklist')->insert($rows);
            }
        }
        $this->counters->board($to);
    }

    public function delete(Board $board, User $actor): void
    {
        if ((int) $board->owner_id !== (int) $actor->id && ! $actor->hasPermission('corkboard.moderate')) {
            throw new PermissionDeniedException();
        }
        $board->deleted_at = Carbon::now();
        $board->save();
        $this->log->touch($board, $actor);
    }

    /** @return list<int> everyone on the team by name, and the owner (groups aren't told about whole-board news) */
    public function teamUserIds(Board $board): array
    {
        $ids = $this->db->table('corkboard_members')->where('board_id', $board->id)->whereNotNull('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($board->owner_id) {
            $ids[] = (int) $board->owner_id;
        }

        return array_values(array_unique($ids));
    }
}
