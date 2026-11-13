<?php

namespace ErnestDefoe\Corkboard\Event;

use ErnestDefoe\Corkboard\Card;
use Flarum\User\User;

/** A finished card isn't finished any more (moved out of done, reopened). */
class CardReopened
{
    public function __construct(public Card $card, public ?User $actor = null)
    {
    }
}
