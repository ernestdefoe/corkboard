<?php

namespace ErnestDefoe\Corkboard\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use ErnestDefoe\Corkboard\Counters;
use Illuminate\Database\ConnectionInterface;

/**
 * Daily: boards deleted more than 30 days ago go for good; done columns with "archive after N
 * days" archive their old cards; history older than two years is trimmed.
 */
class PurgeCommand extends Command
{
    protected $signature = 'corkboard:purge';

    protected $description = 'Remove deleted Corkboard boards after 30 days and archive old done cards.';

    public function handle(ConnectionInterface $db, Counters $counters): int
    {
        $gone = $db->table('corkboard_boards')->whereNotNull('deleted_at')->where('deleted_at', '<', Carbon::now()->subDays(30))->delete();

        // Done cards past their column's auto-archive age: one update per distinct age (a handful),
        // then one recount for every board that lost cards.
        $archived = 0;
        $boards = [];
        $columns = $db->table('corkboard_columns')->whereNotNull('auto_archive_days')->get(['id', 'auto_archive_days'])->groupBy('auto_archive_days');
        foreach ($columns as $days => $group) {
            $due = $db->table('corkboard_cards')->whereIn('column_id', $group->pluck('id'))->whereNull('archived_at')->whereNotNull('completed_at')
                ->where('completed_at', '<', Carbon::now()->subDays((int) $days));
            $boards = array_merge($boards, (clone $due)->distinct()->pluck('board_id')->map(fn ($id) => (int) $id)->all());
            $archived += $due->update(['archived_at' => Carbon::now()]);
        }
        $counters->boards(array_values(array_unique($boards)));

        $db->table('corkboard_activity')->where('created_at', '<', Carbon::now()->subYears(2))->delete();
        $db->table('corkboard_outbox')->where('created_at', '<', Carbon::now()->subDays(2))->delete();

        $this->info("Removed {$gone} deleted boards, archived {$archived} done cards.");

        return 0;
    }
}
