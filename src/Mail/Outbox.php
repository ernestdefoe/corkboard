<?php

namespace ErnestDefoe\Corkboard\Mail;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Notification\DigestBlueprint;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationMailer;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Sends what's waiting in the outbox: one email per person, listing every update since the last
 * one. A person's batch waits while updates are still arriving (the newest is under two minutes
 * old), but never longer than ten minutes. What they can no longer see is dropped.
 */
class Outbox
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly NotificationMailer $mailer,
        private readonly TranslatorInterface $translator,
        private readonly UrlGenerator $url,
    ) {
    }

    public function flush(bool $now = false): int
    {
        $sent = 0;
        $users = $this->db->table('corkboard_outbox')->groupBy('user_id')
            ->selectRaw('user_id, min(created_at) as oldest, max(created_at) as newest')->get();

        foreach ($users as $row) {
            $oldest = Carbon::parse($row->oldest, 'UTC');
            $newest = Carbon::parse($row->newest, 'UTC');
            if (! $now && $newest->gt(Carbon::now()->subMinutes(2)) && $oldest->gt(Carbon::now()->subMinutes(10))) {
                continue;
            }
            $items = $this->db->table('corkboard_outbox')->where('user_id', $row->user_id)->where('created_at', '<=', $row->newest)->orderBy('id')->get();
            $this->db->table('corkboard_outbox')->whereIn('id', $items->pluck('id'))->delete();

            $user = User::query()->find($row->user_id);
            if (! $user || ! $user->is_email_confirmed) {
                continue;
            }
            $lines = $this->lines($user, $items);
            if (! $lines) {
                continue;
            }
            $boards = array_unique(array_column($lines, 'board'));
            $this->translator->setLocale($user->getPreference('locale') ?? $this->translator->getLocale());
            try {
                $this->mailer->send(new DigestBlueprint($lines, count($boards) === 1 ? $boards[0] : null), $user);
                $sent++;
            } catch (\Throwable $e) {
                resolve('log')->warning('[corkboard] digest to '.$user->id.' failed: '.$e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * @param iterable<object> $items
     * @return list<array{text: string, url: string, board: string}>
     */
    private function lines(User $user, iterable $items): array
    {
        $items = collect($items);
        $boards = Board::whereVisibleTo($user)->whereIn('id', $items->pluck('board_id')->unique())->get()->keyBy('id');
        $cards = Card::query()->whereIn('id', $items->pluck('card_id')->filter()->unique())->get()->keyBy('id');
        $actors = User::query()->whereIn('id', $items->pluck('actor_id')->filter()->unique())->get()->keyBy('id');

        $lines = [];
        foreach ($items as $item) {
            $board = $boards[$item->board_id] ?? null;
            $card = $item->card_id ? ($cards[$item->card_id] ?? null) : null;
            if (! $board || ($item->card_id && (! $card || $card->board_id !== $board->id))) {
                continue;
            }
            $params = [
                '{actor}' => $item->actor_id && isset($actors[$item->actor_id]) ? $actors[$item->actor_id]->display_name : $this->translator->trans('ernestdefoe-corkboard.email.someone'),
                '{card}' => $card ? $card->title : '',
                '{board}' => $board->title,
                '{due}' => $card?->due_at ? $card->due_at->format('M j, H:i').' UTC' : '',
            ];
            $base = $this->url->to('forum')->route('corkboard.board', ['id' => $board->slugged()]);
            $lines[] = [
                'text' => $this->translator->trans('ernestdefoe-corkboard.email.line.'.$item->type, $params),
                'url' => $card ? $base.'?card='.$card->id : $base,
                'board' => $board->title,
            ];
        }

        return $lines;
    }
}
