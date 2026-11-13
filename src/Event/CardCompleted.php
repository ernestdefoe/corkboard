<?php

namespace ErnestDefoe\Corkboard\Event;

use ErnestDefoe\Corkboard\Card;
use Flarum\User\User;

/**
 * A card was finished: moved into a done column or marked complete. Fired once per finish (a card
 * reopened and finished again fires again). Renown listens for XP.
 */
class CardCompleted
{
    public function __construct(public Card $card, public ?User $actor = null)
    {
    }
}
