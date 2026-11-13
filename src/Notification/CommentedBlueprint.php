<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Notification\AlertableInterface;

class CommentedBlueprint extends CardBlueprint implements AlertableInterface
{
    public static function getType(): string
    {
        return 'corkboardCommented';
    }
}
