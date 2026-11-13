<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Notification\AlertableInterface;

class DueSoonBlueprint extends CardBlueprint implements AlertableInterface
{
    public static function getType(): string
    {
        return 'corkboardDueSoon';
    }
}
