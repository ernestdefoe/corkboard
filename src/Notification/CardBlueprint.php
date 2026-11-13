<?php

namespace ErnestDefoe\Corkboard\Notification;

use ErnestDefoe\Corkboard\Card;
use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * A Corkboard alert about a card. The subject is the card (the `corkboard-cards` API type), so a
 * person who can no longer see the board stops seeing the alert.
 *
 * 🚨 getData() carries the activity row it came from: NotificationSyncer::sync() hides every
 * existing alert matching the blueprint for anyone not in the new list, so without it assigning a
 * second person would take the first person's alert away.
 */
abstract class CardBlueprint implements BlueprintInterface
{
    public function __construct(public Card $card, public ?User $actor, public int $ref)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->actor;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->card;
    }

    public function getData(): mixed
    {
        return ['r' => $this->ref];
    }

    public static function getSubjectModel(): string
    {
        return Card::class;
    }
}
