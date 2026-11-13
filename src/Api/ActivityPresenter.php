<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Presenter;
use Illuminate\Database\ConnectionInterface;

/**
 * A page of history, newest first. Rows hold ids; this reads today's names for them (people,
 * cards, columns, groups), so a renamed card or a deleted account reads right.
 */
class ActivityPresenter
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Presenter $presenter,
    ) {
    }

    /** @return array<string, mixed> */
    public function page(Board $board, ?int $cardId, ?int $before): array
    {
        $rows = $this->db->table('corkboard_activity')->where('board_id', $board->id)
            ->when($cardId, fn ($q) => $q->where('card_id', $cardId))
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(31)->get();
        $more = $rows->count() > 30;
        $rows = $rows->take(30);

        $users = [];
        $groups = [];
        $items = [];
        foreach ($rows as $row) {
            $data = $row->data ? (array) json_decode($row->data, true) : [];
            if ($row->user_id) {
                $users[] = (int) $row->user_id;
            }
            foreach ((array) ($data['u'] ?? []) as $id) {
                $users[] = (int) $id;
            }
            if (! empty($data['g'])) {
                $groups[] = (int) $data['g'];
            }
            $items[] = [
                'id' => (int) $row->id,
                'type' => $row->type,
                'userId' => $row->user_id === null ? null : (int) $row->user_id,
                'cardId' => $row->card_id === null ? null : (int) $row->card_id,
                'data' => $data ?: (object) [],
                'createdAt' => Presenter::iso($row->created_at),
            ];
        }
        $cardIds = array_values(array_unique(array_filter(array_column($items, 'cardId'))));

        return [
            'items' => $items,
            'more' => $more,
            'users' => $this->presenter->users($users),
            'groups' => $groups ? $this->presenter->groups($groups) : (object) [],
            'cards' => $cardIds ? $this->db->table('corkboard_cards')->where('board_id', $board->id)->whereIn('id', $cardIds)->pluck('title', 'id') : (object) [],
            'columns' => $this->db->table('corkboard_columns')->where('board_id', $board->id)->pluck('title', 'id'),
        ];
    }
}
