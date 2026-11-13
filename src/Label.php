<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $board_id
 * @property string $name
 * @property string $color
 * @property int $position
 */
class Label extends AbstractModel
{
    protected $table = 'corkboard_labels';

    protected $guarded = [];

    protected $casts = ['position' => 'integer'];

    /** The six markers a new board starts with; the team names them. */
    public const DEFAULTS = ['#2b8a3e', '#f2c94c', '#c2410c', '#c92a2a', '#6741d9', '#1971c2'];
}
