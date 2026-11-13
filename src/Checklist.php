<?php

namespace ErnestDefoe\Corkboard;

use Flarum\User\User;

/** A card's checklist: one per card, editors tick and change it. */
class Checklist
{
    public function __construct(
        private readonly Access $access,
        private readonly Positions $positions,
        private readonly Log $log,
        private readonly Counters $counters,
    ) {
    }

    /** @param array<string, mixed> $in text, afterId */
    public function add(Card $card, User $actor, array $in): ChecklistItem
    {
        $this->access->assert($card->board, $actor, 'editor');
        if ($this->counters->checklistCount($card) >= 100) {
            throw Refusal::because('checklist_full', 'text');
        }
        $item = new ChecklistItem();
        $item->card_id = $card->id;
        $item->text = $this->text($in['text'] ?? '');
        $item->is_done = false;
        $item->position = array_key_exists('afterId', $in)
            ? $this->positions->place('corkboard_checklist', 'card_id', $card->id, $in['afterId'] === null ? null : (int) $in['afterId'])
            : $this->positions->last('corkboard_checklist', 'card_id', $card->id);
        $item->save();
        $this->counters->checklist($card);
        $this->log->record($card->board, $card->id, $actor, 'checklist_added');

        return $item;
    }

    /** @param array<string, mixed> $in text, isDone, afterId */
    public function update(ChecklistItem $item, Card $card, User $actor, array $in): ChecklistItem
    {
        $this->access->assert($card->board, $actor, 'editor');
        if (array_key_exists('text', $in)) {
            $item->text = $this->text($in['text']);
        }
        if (array_key_exists('afterId', $in)) {
            $item->position = $this->positions->place('corkboard_checklist', 'card_id', $card->id, $in['afterId'] === null ? null : (int) $in['afterId'], $item->id);
        }
        $ticked = array_key_exists('isDone', $in) && (bool) $in['isDone'] !== $item->is_done;
        if ($ticked) {
            $item->is_done = (bool) $in['isDone'];
        }
        $item->save();
        $this->counters->checklist($card);
        $ticked
            ? $this->log->record($card->board, $card->id, $actor, $item->is_done ? 'checklist_done' : 'checklist_undone', ['i' => $item->id])
            : $this->log->touch($card->board, $actor, $card->id);

        return $item;
    }

    public function delete(ChecklistItem $item, Card $card, User $actor): void
    {
        $this->access->assert($card->board, $actor, 'editor');
        $item->delete();
        $this->counters->checklist($card);
        $this->log->touch($card->board, $actor, $card->id);
    }

    private function text(mixed $value): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($text === '' || mb_strlen($text) > 300) {
            throw Refusal::because('checklist_text', 'text');
        }

        return $text;
    }
}
