<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card. Visible to whoever may see its board (Scope\CardVisibility).
 *
 * @property int $id
 * @property int $board_id
 * @property int $column_id
 * @property int $position
 * @property string $title
 * @property string|null $description
 * @property string $priority
 * @property Carbon|null $due_at
 * @property bool $due_all_day
 * @property Carbon|null $due_notified_at
 * @property Carbon|null $overdue_notified_at
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property Carbon|null $archived_at
 * @property int|null $user_id
 * @property int|null $discussion_id
 * @property string|null $cover_color
 * @property int $comment_count
 * @property int $checklist_total
 * @property int $checklist_done
 * @property Carbon|null $created_at
 * @property Board $board
 */
class Card extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const PRIORITIES = ['none', 'low', 'normal', 'high', 'urgent'];

    protected $table = 'corkboard_cards';

    /**
     * A card is always shown with its board's name, and core's notification list loads card
     * subjects in one query per page: their boards come with them instead of one query per card.
     */
    protected $with = ['board'];

    /** Only cloning a card or a board creates cards from an array (Cards::clone, Boards::copy), with these. */
    protected $fillable = [
        'board_id', 'column_id', 'position', 'title', 'description', 'priority', 'due_at', 'due_all_day',
        'user_id', 'cover_color', 'checklist_total', 'completed_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'due_at' => 'datetime',
        'due_all_day' => 'boolean',
        'due_notified_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
        'comment_count' => 'integer',
        'checklist_total' => 'integer',
        'checklist_done' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = true;

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        // Even a deleted board (the foreign key guarantees one exists): callers check deleted_at.
        return $this->belongsTo(Board::class, 'board_id')->withoutGlobalScope('notDeleted');
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }
}
