<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Notification\AlertableInterface;

class CompletedBlueprint extends BoardBlueprint implements AlertableInterface
{
    public static function getType(): string
    {
        return 'corkboardCompleted';
    }
}
