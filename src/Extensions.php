<?php

namespace ErnestDefoe\Corkboard;

/**
 * Which optional extensions Corkboard works with right now. Each one is switched on by an
 * Integration extender inside Extend\Conditional in extend.php; nothing asks at runtime.
 */
class Extensions
{
    /** @var array<string, true> */
    private array $on = [];

    public function switchOn(string $id): void
    {
        $this->on[$id] = true;
    }

    public function enabled(string $id): bool
    {
        return isset($this->on[$id]);
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
