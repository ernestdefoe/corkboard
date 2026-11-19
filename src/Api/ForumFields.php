<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Settings;
use ErnestDefoe\Corkboard\Templates;
use Flarum\Locale\TranslatorInterface;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** What the forum frontend needs to know before a board loads: what the viewer may do, and the visibility choices. */
class ForumFields
{
    public function __construct(
        private readonly Access $access,
        private readonly Settings $settings,
        private readonly TranslatorInterface $translator,
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
            // The built-in templates, translated, for the new-board picker (template boards are
            // the corkboard-boards templates tab). Signed-in people only: guests start nothing.
            Schema\Arr::make('corkboardTemplates')
                ->visible(fn ($forum, Context $context) => ! $context->getActor()->isGuest())
                ->get(fn () => $this->templates()),
        ];
    }

    /** @return list<array{key: string, title: string, columns: list<string>}> */
    private function templates(): array
    {
        $t = fn (string $key) => $this->translator->trans($key);
        $out = [];
        foreach (array_keys(Templates::BUILT_IN) as $key) {
            $out[] = [
                'key' => $key,
                'title' => $t('ernestdefoe-corkboard.lib.template.'.$key),
                'columns' => array_column(Templates::columns($key, $t), 'title'),
            ];
        }

        return $out;
    }
}
