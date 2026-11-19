<?php

namespace ErnestDefoe\Corkboard\Gdpr;

use Flarum\Gdpr\Data\Type;

/**
 * A member's cards, comments and boards in their data export. Erasing them takes them off every
 * card and team; the boards and cards they made stay (the team's work), passed to nobody
 * (moderators can give a board a new owner). Their comments follow flarum/gdpr's own posts:
 * anonymizing keeps them, shown as by a deleted user; deleting removes them.
 */
class CorkboardData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-corkboard.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-corkboard.lib.gdpr.anonymize');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-corkboard.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        // flarum/gdpr builds types with `new`, so nothing can be injected: the user's own connection.
        $db = $this->user->getConnection();
        $id = $this->user->id;

        return [
            ['corkboard/boards.json' => $this->encodeForExport($db->table('corkboard_boards')->where('owner_id', $id)->get(['id', 'title', 'description', 'visibility', 'status', 'created_at'])->toArray())],
            ['corkboard/cards.json' => $this->encodeForExport($db->table('corkboard_cards')->where('user_id', $id)
                ->orWhereIn('id', fn ($q) => $q->select('card_id')->from('corkboard_assignees')->where('user_id', $id))
                ->get(['id', 'board_id', 'title', 'priority', 'due_at', 'completed_at', 'created_at'])->toArray())],
            ['corkboard/comments.json' => $this->encodeForExport($db->table('corkboard_comments')->where('user_id', $id)->get(['card_id', 'content', 'created_at'])->toArray())],
        ];
    }

    public function anonymize(): void
    {
        $db = $this->user->getConnection();
        $id = $this->user->id;
        $db->table('corkboard_comments')->where('user_id', $id)->update(['user_id' => null]);
        $db->table('corkboard_cards')->where('user_id', $id)->update(['user_id' => null]);
        $db->table('corkboard_boards')->where('owner_id', $id)->update(['owner_id' => null]);
        foreach (['corkboard_assignees', 'corkboard_watchers', 'corkboard_members', 'corkboard_pins', 'corkboard_outbox', 'corkboard_feed_tokens'] as $table) {
            $db->table($table)->where('user_id', $id)->delete();
        }
        $db->table('corkboard_activity')->where('user_id', $id)->update(['user_id' => null]);
        $db->table('corkboard_checklist')->where('user_id', $id)->update(['user_id' => null]);
    }

    /** As anonymize(), and their comments go: each card's comment count is recounted in one update. */
    public function delete(): void
    {
        $db = $this->user->getConnection();
        $cards = $db->table('corkboard_comments')->where('user_id', $this->user->id)->distinct()->pluck('card_id')->all();
        $db->table('corkboard_comments')->where('user_id', $this->user->id)->delete();
        if ($cards) {
            // count(*) as a correlated subquery (as Counters::boards): portable on every driver.
            $db->table('corkboard_cards')->whereIn('id', $cards)->update([
                'comment_count' => $db->table('corkboard_comments')->selectRaw('count(*)')->whereColumn('corkboard_comments.card_id', 'corkboard_cards.id'),
            ]);
        }
        $this->anonymize();
    }
}
