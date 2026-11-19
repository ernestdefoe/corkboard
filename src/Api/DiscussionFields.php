<?php

namespace ErnestDefoe\Corkboard\Api;

use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The "On <board> · <column>" chip: only once some card links a discussion (no query before), and
 * only where discussions themselves are listed or shown (not on every post that includes one).
 */
class DiscussionFields
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly DiscussionCards $cards,
    ) {
    }

    /** @return list<Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('corkboardCards')
                ->visible(fn (Discussion $discussion, Context $context) => ($context->listing(DiscussionResource::class) || $context->showing(DiscussionResource::class))
                    && (bool) $this->settings->get('ernestdefoe-corkboard.linked'))
                ->get(fn (Discussion $discussion, Context $context) => ($this->cards)($discussion, $context)),
        ];
    }
}
