<?php

namespace ErnestDefoe\Corkboard\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A Corkboard widget as a Page Builder block. resolve() hands Page Builder nothing: the browser
 * asks Corkboard's API like every other host, so the viewer's own visibility applies. Registered
 * from extend.php only when Page Builder is installed. Setting keys match js/src/forum/widgets.
 */
abstract class Block implements BlockInterface
{
    public function __construct(protected readonly TranslatorInterface $translator)
    {
    }

    abstract protected function widget(): string;

    /** @return list<array<string, mixed>> */
    abstract public function settingsSchema(): array;

    protected function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-corkboard.lib.widget.'.$key);
    }

    public function type(): string
    {
        return 'corkboard-'.$this->widget();
    }

    public function name(): string
    {
        return $this->t($this->widget().'_title');
    }

    public function category(): string
    {
        return 'content';
    }

    public function defaultSettings(): array
    {
        $out = [];
        foreach ($this->settingsSchema() as $field) {
            $out[$field['key']] = $field['default'];
        }

        return $out;
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
