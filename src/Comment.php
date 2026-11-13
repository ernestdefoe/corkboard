<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quick note on a card. The long conversation belongs in the card's linked discussion.
 *
 * @property int $id
 * @property int $card_id
 * @property int|null $user_id
 * @property string $content
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property User|null $user
 */
class Comment extends AbstractModel
{
    protected $table = 'corkboard_comments';

    protected $guarded = [];

    protected $casts = ['edited_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    public $timestamps = true;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
