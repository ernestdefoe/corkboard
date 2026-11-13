<?php

namespace ErnestDefoe\Corkboard\Notification;

use Flarum\Notification\AlertableInterface;

class TeamAddedBlueprint extends BoardBlueprint implements AlertableInterface
{
    public static function getType(): string
    {
        return 'corkboardTeamAdded';
    }
}
