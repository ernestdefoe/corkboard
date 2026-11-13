<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $card_id
 * @property string $text
 * @property bool $is_done
 * @property int $position
 * @property int|null $user_id
 */
class ChecklistItem extends AbstractModel
{
    protected $table = 'corkboard_checklist';

    protected $guarded = [];

    protected $casts = ['is_done' => 'boolean', 'position' => 'integer'];

    public $timestamps = true;
}
