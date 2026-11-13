<?php

namespace ErnestDefoe\Corkboard\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Daily: boards deleted more than 30 days ago go for good; done columns with "archive after N
 * days" archive their old cards; history older than two years is trimmed.
 */
class PurgeCommand extends Command
{
    protected $signature = 'corkboard:purge';

    protected $description = 'Remove deleted Corkboard boards after 30 days and archive old done cards.';

    public function handle(ConnectionInterface $db): int
    {
        $gone = $db->table('corkboard_boards')->whereNotNull('deleted_at')->where('deleted_at', '<', Carbon::now()->subDays(30))->delete();

        $archived = 0;
        foreach ($db->table('corkboard_columns')->whereNotNull('auto_archive_days')->get(['id', 'board_id', 'auto_archive_days']) as $column) {
            $n = $db->table('corkboard_cards')->where('column_id', $column->id)->whereNull('archived_at')->whereNotNull('completed_at')
                ->where('completed_at', '<', Carbon::now()->subDays((int) $column->auto_archive_days))
                ->update(['archived_at' => Carbon::now()]);
            if ($n) {
                $archived += $n;
                $cards = $db->table('corkboard_cards')->where('board_id', $column->board_id)->whereNull('archived_at');
                $db->table('corkboard_boards')->where('id', $column->board_id)->update([
                    'card_count' => (clone $cards)->count(),
                    'done_count' => (clone $cards)->whereNotNull('completed_at')->count(),
                ]);
                $db->table('corkboard_boards')->where('id', $column->board_id)->increment('version');
            }
        }

        $db->table('corkboard_activity')->where('created_at', '<', Carbon::now()->subYears(2))->delete();
        $db->table('corkboard_outbox')->where('created_at', '<', Carbon::now()->subDays(2))->delete();

        $this->info("Removed {$gone} deleted boards, archived {$archived} done cards.");

        return 0;
    }
}
