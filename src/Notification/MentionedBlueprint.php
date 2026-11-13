<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Notification\AlertableInterface;

class MentionedBlueprint extends CardBlueprint implements AlertableInterface
{
    public static function getType(): string
    {
        return 'corkboardMentioned';
    }
}
