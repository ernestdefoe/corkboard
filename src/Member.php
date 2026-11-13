<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Database\AbstractModel;

/**
 * Someone on a board's team: a member by name, or a whole group.
 *
 * @property int $id
 * @property int $board_id
 * @property int|null $user_id
 * @property int|null $group_id
 * @property string $role
 * @property \Carbon\Carbon|null $created_at
 */
class Member extends AbstractModel
{
    public const ROLES = ['viewer', 'commenter', 'editor', 'manager'];

    protected $table = 'corkboard_members';

    protected $guarded = [];

    public $timestamps = false;
}
