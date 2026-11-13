<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Event\CardCompleted;
use ErnestDefoe\Corkboard\Event\CardDeleted;
use ErnestDefoe\Corkboard\Event\CardReopened;
use Flarum\Discussion\Discussion;
use Flarum\Formatter\Formatter;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Everything that changes a card. Each change is one line of history (Log), and the board's
 * version moves so open boards catch up.
 *
 * Done: a card is finished when it enters a done column or is marked complete (which moves it to
 * the first done column, if the board has one), and unfinished when it leaves one or is reopened.
 * Either way CardCompleted / CardReopened fire once (Renown listens).
 */
class Cards
{
    /** @var list<object> events to fire once the transaction has committed */
    private array $events = [];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Positions $positions,
        private readonly Log $log,
        private readonly Notify $notify,
        private readonly Counters $counters,
        private readonly Formatter $formatter,
        private readonly Dispatcher $dispatcher,
    ) {
    }

    /** @param array<string, mixed> $in columnId, title, afterId (absent: last; null: first), and any update() field */
    public function create(Board $board, User $actor, array $in): Card
    {
        $this->access->assert($board, $actor, 'editor');
        $column = $this->column($board, $in['columnId'] ?? null);

        $card = $this->run(function () use ($board, $actor, $in, $column) {
            $card = new Card();
            $card->board_id = $board->id;
            $card->column_id = $column->id;
            $card->title = $this->title($in['title'] ?? '');
            $card->user_id = $actor->id;
            $card->priority = 'none';
            $card->position = array_key_exists('afterId', $in)
                ? $this->positions->place('corkboard_cards', 'column_id', $column->id, $in['afterId'] === null ? null : (int) $in['afterId'])
                : $this->positions->last('corkboard_cards', 'column_id', $column->id);
            if ($column->is_done) {
                $card->completed_at = Carbon::now();
                $card->completed_by = $actor->id;
            }
            $card->save();
            $card->setRelation('board', $board);
            $this->notify->watch($card, $actor->id);
            $this->log->record($board, $card->id, $actor, 'card_created', ['col' => $column->id]);

            unset($in['columnId'], $in['afterId'], $in['title']);
            $this->apply($card, $actor, $in);
            $this->counters->board($board);

            return $card;
        });

        return $card;
    }

    /** @param array<string, mixed> $in */
    public function update(Card $card, User $actor, array $in): Card
    {
        $board = $card->board;

        if (array_key_exists('watching', $in)) {
            $this->access->assert($board, $actor, 'viewer');
            $actor->assertRegistered();
            $this->db->table('corkboard_watchers')->updateOrInsert(['card_id' => $card->id, 'user_id' => $actor->id], ['watching' => (bool) $in['watching']]);
            unset($in['watching']);
        }
        if (! $in) {
            return $card;
        }
        $this->access->assert($board, $actor, 'editor');

        return $this->run(function () use ($card, $actor, $in, $board) {
            if (array_key_exists('title', $in)) {
                $title = $this->title($in['title']);
                if ($title !== $card->title) {
                    $card->title = $title;
                    $card->save();
                    $this->log->record($board, $card->id, $actor, 'card_renamed');
                }
                unset($in['title']);
            }
            $this->apply($card, $actor, $in);
            $this->counters->board($board);

            return $card;
        });
    }

    /** @param array<string, mixed> $in */
    private function apply(Card $card, User $actor, array $in): void
    {
        $board = $card->board;

        if (array_key_exists('description', $in)) {
            $text = trim((string) $in['description']);
            if (mb_strlen($text) > 20000) {
                throw Refusal::because('too_long', 'description');
            }
            $before = Notify::mentioned($card->description);
            $card->description = $text === '' ? null : $this->formatter->parse($text, null, $actor);
            $card->save();
            $ref = $this->log->record($board, $card->id, $actor, 'card_described');
            $this->notify->send(Notification\MentionedBlueprint::class, $card, $actor, array_diff(Notify::mentioned($card->description), $before), $ref);
        }

        if (array_key_exists('priority', $in) && in_array($in['priority'], Card::PRIORITIES, true) && $in['priority'] !== $card->priority) {
            $card->priority = $in['priority'];
            $card->save();
            $this->log->record($board, $card->id, $actor, 'card_priority', ['p' => $card->priority]);
        }

        if (array_key_exists('dueAt', $in) || array_key_exists('dueAllDay', $in)) {
            $due = array_key_exists('dueAt', $in) ? ($in['dueAt'] ? Carbon::parse((string) $in['dueAt'])->utc() : null) : $card->due_at;
            $allDay = array_key_exists('dueAllDay', $in) ? (bool) $in['dueAllDay'] : $card->due_all_day;
            if ($due?->toIso8601String() !== $card->due_at?->toIso8601String() || $allDay !== $card->due_all_day) {
                $card->due_at = $due;
                $card->due_all_day = $due !== null && $allDay;
                $card->due_notified_at = null;
                $card->overdue_notified_at = null;
                $card->save();
                $this->log->record($board, $card->id, $actor, 'card_due', ['d' => $due?->toIso8601String()]);
            }
        }

        if (array_key_exists('coverColor', $in)) {
            $color = $in['coverColor'];
            $card->cover_color = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;
            $card->save();
            $this->log->touch($board, $actor, $card->id);
        }

        if (array_key_exists('labelIds', $in)) {
            $ids = Label::query()->where('board_id', $board->id)->whereIn('id', array_map('intval', (array) $in['labelIds']))->pluck('id')->all();
            $this->db->table('corkboard_card_label')->where('card_id', $card->id)->delete();
            $this->db->table('corkboard_card_label')->insert(array_map(fn ($id) => ['card_id' => $card->id, 'label_id' => $id], $ids));
            $this->log->record($board, $card->id, $actor, 'card_labels', ['l' => $ids]);
        }

        if (array_key_exists('assigneeIds', $in)) {
            $this->assign($card, $actor, array_map('intval', (array) $in['assigneeIds']));
        }

        if (array_key_exists('discussionId', $in)) {
            $id = $in['discussionId'] ? (int) $in['discussionId'] : null;
            if ($id !== null && ! Discussion::whereVisibleTo($actor)->whereKey($id)->exists()) {
                throw Refusal::because('discussion', 'discussionId');
            }
            $card->discussion_id = $id;
            $card->save();
            $id && Discussions::markLinked();
            $this->log->record($board, $card->id, $actor, $id ? 'card_linked' : 'card_unlinked', $id ? ['d' => $id] : []);
        }

        if (array_key_exists('columnId', $in)) {
            $this->move($card, $actor, $this->column($board, $in['columnId']), array_key_exists('afterId', $in) ? ($in['afterId'] === null ? null : (int) $in['afterId']) : false);
        }

        if (array_key_exists('completed', $in)) {
            (bool) $in['completed'] ? $this->complete($card, $actor) : $this->reopen($card, $actor);
        }

        if (array_key_exists('archived', $in)) {
            $archive = (bool) $in['archived'];
            if ($archive !== ($card->archived_at !== null)) {
                $card->archived_at = $archive ? Carbon::now() : null;
                if (! $archive) {
                    // Back at the bottom of its column.
                    $card->position = $this->positions->last('corkboard_cards', 'column_id', $card->column_id);
                }
                $card->save();
                $this->log->record($board, $card->id, $actor, $archive ? 'card_archived' : 'card_restored');
            }
        }
    }

    /** @param list<int> $ids */
    private function assign(Card $card, User $actor, array $ids): void
    {
        $board = $card->board;
        $current = $this->db->table('corkboard_assignees')->where('card_id', $card->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $wanted = array_values(array_unique(array_filter($ids)));

        // Only people who can see the board can be put on its cards.
        $added = array_values(array_diff($wanted, $current));
        if ($added) {
            $added = User::query()->whereIn('id', $added)->get()
                ->filter(fn (User $u) => Board::whereVisibleTo($u)->whereKey($board->id)->exists())
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        $removed = array_values(array_diff($current, $wanted));

        if ($removed) {
            $this->db->table('corkboard_assignees')->where('card_id', $card->id)->whereIn('user_id', $removed)->delete();
            $this->log->record($board, $card->id, $actor, 'card_unassigned', ['u' => $removed]);
        }
        if ($added) {
            $now = Carbon::now();
            $this->db->table('corkboard_assignees')->insert(array_map(fn ($id) => ['card_id' => $card->id, 'user_id' => $id, 'assigned_by' => $actor->id, 'created_at' => $now], $added));
            foreach ($added as $id) {
                $this->notify->watch($card, $id);
            }
            $ref = $this->log->record($board, $card->id, $actor, 'card_assigned', ['u' => $added]);
            $this->notify->send(Notification\AssignedBlueprint::class, $card, $actor, $added, $ref);
        }
    }

    /** @param int|null|false $afterId false: the bottom; null: the top */
    private function move(Card $card, User $actor, Column $to, int|null|false $afterId): void
    {
        $from = $card->column_id;
        $card->position = $afterId === false
            ? ($from === $to->id ? $card->position : $this->positions->last('corkboard_cards', 'column_id', $to->id))
            : $this->positions->place('corkboard_cards', 'column_id', $to->id, $afterId, $card->id);
        $card->column_id = $to->id;
        $card->save();

        if ($from !== $to->id) {
            $this->log->record($card->board, $card->id, $actor, 'card_moved', ['from' => $from, 'to' => $to->id]);
            if ($to->is_done) {
                $this->finish($card, $actor);
            } elseif ($card->completed_at !== null && Column::query()->whereKey($from)->value('is_done')) {
                $this->unfinish($card, $actor);
            }
        } else {
            $this->log->touch($card->board, $actor, $card->id);
        }
    }

    private function complete(Card $card, User $actor): void
    {
        if ($card->completed_at !== null) {
            return;
        }
        $done = Column::query()->where('board_id', $card->board_id)->where('is_done', true)->orderBy('position')->first();
        if ($done && $done->id !== $card->column_id) {
            $card->position = $this->positions->place('corkboard_cards', 'column_id', $done->id, null, $card->id);
            $card->column_id = $done->id;
        }
        $this->finish($card, $actor);
    }

    private function reopen(Card $card, User $actor): void
    {
        if ($card->completed_at === null) {
            return;
        }
        if (Column::query()->whereKey($card->column_id)->value('is_done')) {
            $back = Column::query()->where('board_id', $card->board_id)->where('is_done', false)->orderByDesc('position')->first();
            if ($back) {
                $card->column_id = $back->id;
                $card->position = $this->positions->last('corkboard_cards', 'column_id', $back->id);
            }
        }
        $this->unfinish($card, $actor);
    }

    private function finish(Card $card, User $actor): void
    {
        if ($card->completed_at !== null) {
            $card->save();

            return;
        }
        $card->completed_at = Carbon::now();
        $card->completed_by = $actor->id;
        $card->save();
        $ref = $this->log->record($card->board, $card->id, $actor, 'card_completed');
        $this->notify->send(Notification\DoneBlueprint::class, $card, $actor, $this->notify->watchers($card), $ref);
        $this->events[] = new CardCompleted($card, $actor);
    }

    private function unfinish(Card $card, User $actor): void
    {
        $card->completed_at = null;
        $card->completed_by = null;
        $card->save();
        $this->log->record($card->board, $card->id, $actor, 'card_reopened');
        $this->events[] = new CardReopened($card, $actor);
    }

    public function delete(Card $card, User $actor): void
    {
        $board = $card->board;
        $owner = ! $actor->isGuest() && (int) $card->user_id === (int) $actor->id && $this->access->can($board, $actor, 'editor');
        if (! $owner && ! $this->access->can($board, $actor, 'manager')) {
            throw new PermissionDeniedException();
        }
        $this->dispatcher->dispatch(new CardDeleted($card, $actor));
        $this->db->transaction(function () use ($card, $board, $actor) {
            $card->delete();
            $this->log->record($board, null, $actor, 'card_deleted', ['t' => $card->title]);
            $this->counters->board($board);
        });
    }

    /** @param array<string, mixed> $in boardId, columnId */
    public function clone(Card $card, User $actor, array $in): Card
    {
        $this->access->assert($card->board, $actor, 'viewer');
        $board = isset($in['boardId']) ? Board::whereVisibleTo($actor)->findOrFail((int) $in['boardId']) : $card->board;
        $this->access->assert($board, $actor, 'editor');
        $column = $this->column($board, $in['columnId'] ?? Column::query()->where('board_id', $board->id)->orderBy('position')->value('id'));

        return $this->run(function () use ($card, $actor, $board, $column) {
            $copy = Card::query()->create([
                'board_id' => $board->id, 'column_id' => $column->id,
                'position' => $this->positions->last('corkboard_cards', 'column_id', $column->id),
                'title' => $card->title, 'description' => $card->description, 'priority' => $card->priority,
                'due_at' => $card->due_at, 'due_all_day' => $card->due_all_day, 'user_id' => $actor->id,
                'cover_color' => $card->cover_color, 'checklist_total' => $card->checklist_total,
                'completed_at' => $column->is_done ? Carbon::now() : null,
            ]);
            if ($board->id === $card->board_id) {
                $labels = $this->db->table('corkboard_card_label')->where('card_id', $card->id)->pluck('label_id');
                $this->db->table('corkboard_card_label')->insert($labels->map(fn ($id) => ['card_id' => $copy->id, 'label_id' => $id])->all());
            }
            $rows = $this->db->table('corkboard_checklist')->where('card_id', $card->id)->orderBy('position')->get()
                ->map(fn ($i) => ['card_id' => $copy->id, 'text' => $i->text, 'is_done' => false, 'position' => $i->position, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()])->all();
            $rows && $this->db->table('corkboard_checklist')->insert($rows);
            $this->notify->watch($copy, $actor->id);
            $copy->setRelation('board', $board);
            $this->log->record($board, $copy->id, $actor, 'card_cloned', ['from' => $card->id]);
            $this->counters->board($board);

            return $copy;
        });
    }

    /** @return list<object> what the transaction queued, now it has committed */
    private function takeEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function column(Board $board, mixed $id): Column
    {
        $column = $id ? Column::query()->where('board_id', $board->id)->find((int) $id) : null;
        if (! $column) {
            throw Refusal::because('column', 'columnId');
        }

        return $column;
    }

    public function title(mixed $value): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($title === '' || mb_strlen($title) > 160) {
            throw Refusal::because('card_title', 'title');
        }

        return $title;
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function run(\Closure $fn): mixed
    {
        $this->events = [];
        $result = $this->db->transaction($fn);
        foreach ($this->takeEvents() as $event) {
            $this->dispatcher->dispatch($event);
        }

        return $result;
    }
}
