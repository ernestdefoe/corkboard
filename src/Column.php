<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $board_id
 * @property string $title
 * @property string|null $color
 * @property int $position
 * @property int|null $wip_limit
 * @property bool $is_done
 * @property int|null $auto_archive_days
 */
class Column extends AbstractModel
{
    protected $table = 'corkboard_columns';

    protected $casts = ['is_done' => 'boolean', 'position' => 'integer', 'wip_limit' => 'integer', 'auto_archive_days' => 'integer'];

    public $timestamps = true;
}
