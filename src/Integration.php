<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/** Switches an integration on; extend.php uses it inside Extend\Conditional, so it only runs when that extension is enabled. */
class Integration implements ExtenderInterface
{
    public function __construct(private readonly string $id)
    {
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $container->resolving(Extensions::class, fn (Extensions $extensions) => $extensions->switchOn($this->id));
    }
}
