<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property Column $column
 * @property User|null $user
 * @property Discussion|null $discussion
 */
class Card extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const PRIORITIES = ['none', 'low', 'normal', 'high', 'urgent'];

    protected $table = 'corkboard_cards';

    protected $guarded = [];

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

    /** @return BelongsTo<Column, $this> */
    public function column(): BelongsTo
    {
        return $this->belongsTo(Column::class, 'column_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'corkboard_assignees', 'card_id', 'user_id');
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'corkboard_card_label', 'card_id', 'label_id');
    }

    /** @return HasMany<ChecklistItem, $this> */
    public function checklist(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'card_id')->orderBy('position')->orderBy('id');
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }
}
