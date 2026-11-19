<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * One instance each for what keeps per-request memos (keyed by the actor or the request object, in
 * WeakMaps, so nothing outlives what it describes) and for the integration switches.
 */
class CorkboardServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Extensions::class);
        $this->container->singleton(Access::class);
        $this->container->singleton(Api\DiscussionCards::class);
    }
}
