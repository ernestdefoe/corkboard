<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A board: columns of cards, a team, and who may see it (Scope\BoardVisibility).
 *
 * @property int $id
 * @property int|null $owner_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property string $color
 * @property string $visibility
 * @property string $status
 * @property int|null $tag_id
 * @property int|null $project_id
 * @property bool $is_template
 * @property int $card_count
 * @property int $done_count
 * @property int $version
 * @property Carbon|null $completed_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property User|null $owner
 */
class Board extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const VISIBILITIES = ['public', 'members', 'team', 'private'];
    public const STATUSES = ['active', 'completed', 'archived'];

    protected $table = 'corkboard_boards';

    protected $guarded = [];

    protected $casts = [
        'is_template' => 'boolean',
        'card_count' => 'integer',
        'done_count' => 'integer',
        'version' => 'integer',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = true;

    /** Soft-deleted boards are gone for everyone; Console\PurgeCommand removes them after 30 days. */
    protected static function booted(): void
    {
        static::addGlobalScope('notDeleted', fn (Builder $q) => $q->whereNull('corkboard_boards.deleted_at'));
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Column, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(Column::class, 'board_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class, 'board_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'board_id');
    }

    /** Completed and archived boards are read-only until a manager reopens them. */
    public function isReadOnly(): bool
    {
        return $this->status !== 'active';
    }

    public function slugged(): string
    {
        return $this->id.($this->slug !== '' ? '-'.$this->slug : '');
    }
}
