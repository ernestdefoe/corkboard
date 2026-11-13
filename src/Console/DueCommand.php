<?php

namespace ErnestDefoe\Corkboard\Console;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Notification\DueSoonBlueprint;
use ErnestDefoe\Corkboard\Notification\OverdueBlueprint;
use ErnestDefoe\Corkboard\Notify;
use ErnestDefoe\Corkboard\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;

/**
 * Hourly: "due soon" to a card's assignees (its owner when nobody is assigned) once per due date,
 * and "overdue" once, the first run after it passed. Changing the due date arms both again.
 */
class DueCommand extends Command
{
    protected $signature = 'corkboard:due';

    protected $description = 'Tell people about Corkboard cards that are due soon or overdue.';

    public function handle(ConnectionInterface $db, Notify $notify, Settings $settings): int
    {
        $now = Carbon::now();
        $sent = 0;
        $open = fn () => Card::query()->whereNull('completed_at')->whereNull('archived_at')->whereNotNull('due_at')
            ->whereIn('board_id', fn ($b) => $b->select('id')->from('corkboard_boards')->where('status', 'active')->whereNull('deleted_at'));

        $soon = $open()->whereNull('due_notified_at')->where('due_at', '>', $now)->where('due_at', '<=', $now->copy()->addHours($settings->dueSoonHours()));
        $soon->with('board')->chunkById(200, function ($cards) use ($db, $notify, &$sent) {
            $this->notifyAll($cards, $db, $notify, DueSoonBlueprint::class, 'due_notified_at', $sent);
        });

        $late = $open()->whereNull('overdue_notified_at')->where('due_at', '<=', $now)->where('due_at', '>', $now->copy()->subDays(7));
        $late->with('board')->chunkById(200, function ($cards) use ($db, $notify, &$sent) {
            $this->notifyAll($cards, $db, $notify, OverdueBlueprint::class, 'overdue_notified_at', $sent);
        });

        $this->info("Sent {$sent} reminders.");

        return 0;
    }

    /**
     * @param iterable<Card> $cards
     * @param class-string<DueSoonBlueprint|OverdueBlueprint> $blueprint
     */
    private function notifyAll(iterable $cards, ConnectionInterface $db, Notify $notify, string $blueprint, string $stamp, int &$sent): void
    {
        $cards = collect($cards);
        $assignees = $db->table('corkboard_assignees')->whereIn('card_id', $cards->pluck('id'))->get(['card_id', 'user_id'])->groupBy('card_id');
        foreach ($cards as $card) {
            if ($card->board->deleted_at !== null) {
                continue;
            }
            $people = ($assignees[$card->id] ?? collect())->pluck('user_id')->all() ?: [$card->user_id];
            $db->table('corkboard_cards')->where('id', $card->id)->update([$stamp => Carbon::now()]);
            $notify->send($blueprint, $card, null, $people, (int) $card->due_at->timestamp);
            $sent++;
        }
    }
}
