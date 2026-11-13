<?php

namespace ErnestDefoe\Corkboard\Event;

use ErnestDefoe\Corkboard\Card;
use Flarum\User\User;

/** A card is gone for good (fired before the row is deleted, so its relations can still be read). */
class CardDeleted
{
    public function __construct(public Card $card, public ?User $actor = null)
    {
    }
}
