<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Extension\ExtensionManager;

/** Which optional extensions are switched on. No queries: the enabled list is already loaded. */
class Extensions
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly ExtensionManager $extensions)
    {
    }

    public function enabled(string $id): bool
    {
        return $this->memo[$id] ??= $this->extensions->isEnabled($id);
    }

    public function tags(): bool
    {
        return $this->enabled('flarum-tags');
    }

    public function projects(): bool
    {
        return $this->enabled('ernestdefoe-projects');
    }
}
