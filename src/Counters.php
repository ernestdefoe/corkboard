<?php

namespace ErnestDefoe\Corkboard;

use Illuminate\Database\ConnectionInterface;

/**
 * Cached counts, recounted rather than incremented: a recount can't drift, and an unsigned
 * column never goes below zero (MySQL makes that an error, not a negative).
 */
class Counters
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function board(Board $board): void
    {
        $cards = $this->db->table('corkboard_cards')->where('board_id', $board->id)->whereNull('archived_at');
        $board->card_count = (clone $cards)->count();
        $board->done_count = (clone $cards)->whereNotNull('completed_at')->count();
        $this->db->table('corkboard_boards')->where('id', $board->id)->update(['card_count' => $board->card_count, 'done_count' => $board->done_count]);
    }

    public function checklist(Card $card): void
    {
        $items = $this->db->table('corkboard_checklist')->where('card_id', $card->id);
        $card->checklist_total = (clone $items)->count();
        $card->checklist_done = (clone $items)->where('is_done', true)->count();
        $this->db->table('corkboard_cards')->where('id', $card->id)->update(['checklist_total' => $card->checklist_total, 'checklist_done' => $card->checklist_done]);
    }

    public function checklistCount(Card $card): int
    {
        return $this->db->table('corkboard_checklist')->where('card_id', $card->id)->count();
    }

    public function comments(Card $card): void
    {
        $card->comment_count = $this->db->table('corkboard_comments')->where('card_id', $card->id)->count();
        $this->db->table('corkboard_cards')->where('id', $card->id)->update(['comment_count' => $card->comment_count]);
    }
}
