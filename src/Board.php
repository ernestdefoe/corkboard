<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Database\ScopeVisibilityTrait;
use Illuminate\Database\Eloquent\Builder;

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
 */
class Board extends AbstractModel
{
    use ScopeVisibilityTrait;

    public const VISIBILITIES = ['public', 'members', 'team', 'private'];
    public const STATUSES = ['active', 'completed', 'archived'];

    protected $table = 'corkboard_boards';

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
