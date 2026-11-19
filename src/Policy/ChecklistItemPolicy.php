<?php

namespace ErnestDefoe\Corkboard\Policy;

use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\ChecklistItem;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** A checklist item is changed by whoever may edit its card (CardPolicy::update). */
class ChecklistItemPolicy extends AbstractPolicy
{
    public function update(User $actor, ChecklistItem $item): bool
    {
        $card = Card::query()->find($item->card_id);

        return $card !== null && $actor->can('update', $card);
    }

    public function delete(User $actor, ChecklistItem $item): bool
    {
        return $this->update($actor, $item);
    }
}
