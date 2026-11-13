<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Notification\AlertableInterface;

class AssignedBlueprint extends CardBlueprint implements AlertableInterface
{
    public static function getType(): string
    {
        return 'corkboardAssigned';
    }
}
