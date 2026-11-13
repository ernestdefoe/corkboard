<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'corkboard.view' => Group::GUEST_ID,
    'corkboard.create' => Group::MEMBER_ID,
    'corkboard.useTemplates' => Group::MEMBER_ID,
    'corkboard.createPublic' => Group::MODERATOR_ID,
    'corkboard.moderate' => Group::MODERATOR_ID,
]);
