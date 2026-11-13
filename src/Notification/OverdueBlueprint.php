<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Notification\AlertableInterface;

class OverdueBlueprint extends CardBlueprint implements AlertableInterface
{
    public static function getType(): string
    {
        return 'corkboardOverdue';
    }
}
