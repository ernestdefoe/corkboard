<?php

namespace ErnestDefoe\Corkboard;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** Columns and labels (managers only). */
class Structure
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Access $access,
        private readonly Positions $positions,
        private readonly Log $log,
        private readonly Counters $counters,
    ) {
    }

    /** @param array<string, mixed> $in title, afterId, isDone, wipLimit, color, autoArchiveDays */
    public function addColumn(Board $board, User $actor, array $in): Column
    {
        $this->access->assert($board, $actor, 'manager');
        if (Column::query()->where('board_id', $board->id)->count() >= 30) {
            throw Refusal::because('columns_full', 'title');
        }
        $column = new Column();
        $column->board_id = $board->id;
        $column->position = array_key_exists('afterId', $in)
            ? $this->positions->place('corkboard_columns', 'board_id', $board->id, $in['afterId'] === null ? null : (int) $in['afterId'])
            : $this->positions->last('corkboard_columns', 'board_id', $board->id);
        $this->fillColumn($column, $in + ['title' => ''], true);
        $column->save();
        $this->log->record($board, null, $actor, 'column_added', ['col' => $column->id]);

        return $column;
    }

    /** @param array<string, mixed> $in */
    public function updateColumn(Column $column, Board $board, User $actor, array $in): Column
    {
        $this->access->assert($board, $actor, 'manager');
        if (array_key_exists('afterId', $in)) {
            $column->position = $this->positions->place('corkboard_columns', 'board_id', $board->id, $in['afterId'] === null ? null : (int) $in['afterId'], $column->id);
        }
        $wasDone = $column->is_done;
        $this->fillColumn($column, $in, false);
        $column->save();
        if ($wasDone !== $column->is_done) {
            // A column that becomes a done column finishes its cards; one that stops being one doesn't
            // reopen them (nobody asked for that): the cards keep their finished date.
            if ($column->is_done) {
                $this->db->table('corkboard_cards')->where('column_id', $column->id)->whereNull('completed_at')
                    ->update(['completed_at' => \Carbon\Carbon::now(), 'completed_by' => $actor->id]);
            }
            $this->counters->board($board);
        }
        $this->log->record($board, null, $actor, 'column_updated', ['col' => $column->id]);

        return $column;
    }

    public function deleteColumn(Column $column, Board $board, User $actor): void
    {
        $this->access->assert($board, $actor, 'manager');
        if ($this->db->table('corkboard_cards')->where('column_id', $column->id)->exists()) {
            throw Refusal::because('column_not_empty');
        }
        if (Column::query()->where('board_id', $board->id)->count() <= 1) {
            throw Refusal::because('last_column');
        }
        $column->delete();
        $this->log->record($board, null, $actor, 'column_removed', ['t' => $column->title]);
    }

    /** @param array<string, mixed> $in */
    private function fillColumn(Column $column, array $in, bool $creating): void
    {
        if ($creating || array_key_exists('title', $in)) {
            $title = trim(preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')) ?? '');
            if ($title === '' || mb_strlen($title) > 80) {
                throw Refusal::because('column_title', 'title');
            }
            $column->title = $title;
        }
        if (array_key_exists('isDone', $in)) {
            $column->is_done = (bool) $in['isDone'];
        }
        if (array_key_exists('wipLimit', $in)) {
            $column->wip_limit = $in['wipLimit'] ? max(1, min(999, (int) $in['wipLimit'])) : null;
        }
        if (array_key_exists('autoArchiveDays', $in)) {
            $column->auto_archive_days = $in['autoArchiveDays'] ? max(1, min(365, (int) $in['autoArchiveDays'])) : null;
        }
        if (array_key_exists('color', $in)) {
            $column->color = is_string($in['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $in['color']) ? $in['color'] : null;
        }
    }

    /** @param array<string, mixed> $in name, color */
    public function addLabel(Board $board, User $actor, array $in): Label
    {
        $this->access->assert($board, $actor, 'manager');
        if (Label::query()->where('board_id', $board->id)->count() >= 40) {
            throw Refusal::because('labels_full', 'name');
        }
        $label = new Label();
        $label->board_id = $board->id;
        $label->position = (int) Label::query()->where('board_id', $board->id)->max('position') + 1;
        $this->fillLabel($label, $in + ['color' => Label::DEFAULTS[5]]);
        $label->save();
        $this->log->touch($board, $actor);

        return $label;
    }

    /** @param array<string, mixed> $in */
    public function updateLabel(Label $label, Board $board, User $actor, array $in): Label
    {
        $this->access->assert($board, $actor, 'manager');
        $this->fillLabel($label, $in);
        $label->save();
        $this->log->touch($board, $actor);

        return $label;
    }

    public function deleteLabel(Label $label, Board $board, User $actor): void
    {
        $this->access->assert($board, $actor, 'manager');
        $label->delete();
        $this->log->touch($board, $actor);
    }

    /** @param array<string, mixed> $in */
    private function fillLabel(Label $label, array $in): void
    {
        if (array_key_exists('name', $in)) {
            $label->name = mb_substr(trim((string) $in['name']), 0, 40);
        }
        if (array_key_exists('color', $in) && is_string($in['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $in['color'])) {
            $label->color = $in['color'];
        }
    }
}
