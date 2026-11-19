<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** What the forum frontend needs to know before a board loads: what the viewer may do, and the visibility choices. */
class ForumFields
{
    public function __construct(
        private readonly Access $access,
        private readonly Settings $settings,
    ) {
    }

    /** @return list<Schema\Boolean|Schema\Arr|Schema\Str> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('corkboardCanCreate')
                ->get(fn ($forum, Context $context) => $this->access->createRefusal($context->getActor(), $this->settings) === null),
            Schema\Boolean::make('corkboardCanPublic')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('corkboard.createPublic')),
            Schema\Boolean::make('corkboardCanModerate')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('corkboard.moderate')),
            Schema\Boolean::make('corkboardCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('corkboard.view') || ! $context->getActor()->isGuest()),
            Schema\Arr::make('corkboardVisibilities')
                ->get(fn () => $this->settings->visibilities()),
            Schema\Str::make('corkboardDefaultVisibility')
                ->get(fn () => $this->settings->defaultVisibility()),
        ];
    }
}
