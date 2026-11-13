<?php

namespace ErnestDefoe\Corkboard\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's cards, comments and boards in their data export. Erasing them keeps their comments
 * (as "Deleted user") so a team's history still reads, and takes them off every card and team;
 * the boards they own pass to nobody (moderators can give them a new owner).
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
        return self::anonymizeDescription();
    }

    public function export(): ?array
    {
        $db = resolve(ConnectionInterface::class);
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
        $db = resolve(ConnectionInterface::class);
        $id = $this->user->id;
        $db->table('corkboard_comments')->where('user_id', $id)->update(['user_id' => null]);
        $db->table('corkboard_cards')->where('user_id', $id)->update(['user_id' => null]);
        $db->table('corkboard_boards')->where('owner_id', $id)->update(['owner_id' => null]);
        foreach (['corkboard_assignees', 'corkboard_watchers', 'corkboard_members', 'corkboard_pins', 'corkboard_outbox'] as $table) {
            $db->table($table)->where('user_id', $id)->delete();
        }
        $db->table('corkboard_activity')->where('user_id', $id)->update(['user_id' => null]);
        $db->table('corkboard_checklist')->where('user_id', $id)->update(['user_id' => null]);
    }

    public function delete(): void
    {
        $this->anonymize();
    }
}
