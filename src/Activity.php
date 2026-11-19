<?php

namespace ErnestDefoe\Corkboard;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A line of a board's history (written by Log, read through Api\ActivityResource). It holds ids
 * only; names and titles are read when it's shown.
 *
 * @property int $id
 * @property int $board_id
 * @property int|null $card_id
 * @property int|null $user_id
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 */
class Activity extends AbstractModel
{
    protected $table = 'corkboard_activity';

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];
}
