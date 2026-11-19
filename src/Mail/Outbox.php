<?php

namespace ErnestDefoe\Corkboard\Mail;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Notification\DigestBlueprint;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationMailer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * A fixed number of queries per run (the waiting rows, the people, the cards and the actors
     * named, the clean-up), plus one per person for the boards they may still see.
     */
    public function flush(bool $now = false): int
    {
        $ready = $this->ready($now);
        if ($ready->isEmpty()) {
            return 0;
        }
        $all = $this->db->table('corkboard_outbox')->whereIn('user_id', $ready->keys())->orderBy('id')->get();
        // Only what was waiting when the batch was read: a row written since goes next time.
        $all = $all->filter(fn ($item) => Carbon::parse($item->created_at, 'UTC')->lte($ready[$item->user_id]));
        $this->db->table('corkboard_outbox')->whereIn('id', $all->pluck('id'))->delete();

        $users = User::query()->whereIn('id', $ready->keys())->get()->keyBy('id');
        $cards = Card::query()->whereIn('id', $all->pluck('card_id')->filter()->unique())->get()->keyBy('id');
        $actors = User::query()->whereIn('id', $all->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        $locale = $this->translator->getLocale();
        $sent = 0;
        try {
            foreach ($all->groupBy('user_id') as $userId => $items) {
                $user = $users[$userId] ?? null;
                if ($user && $user->is_email_confirmed && $this->send($user, $items, $cards, $actors)) {
                    $sent++;
                }
            }
        } finally {
            $this->translator->setLocale($locale);
        }

        return $sent;
    }

    /**
     * Each person whose batch is ready, with the newest update in it: one grouped query (plain
     * min/max: portable). A batch waits while updates still arrive (the newest is under two
     * minutes old), never longer than ten.
     *
     * @return Collection<int, Carbon>
     */
    private function ready(bool $now): Collection
    {
        $rows = $this->db->table('corkboard_outbox')->groupBy('user_id')
            ->selectRaw('user_id, min(created_at) as oldest, max(created_at) as newest')->get();
        $ready = collect();
        foreach ($rows as $row) {
            $oldest = Carbon::parse($row->oldest, 'UTC');
            $newest = Carbon::parse($row->newest, 'UTC');
            if ($now || $newest->lte(Carbon::now()->subMinutes(2)) || $oldest->lte(Carbon::now()->subMinutes(10))) {
                $ready[(int) $row->user_id] = $newest;
            }
        }

        return $ready;
    }

    /**
     * One person's email, written in their language (or the forum's): the locale is set before
     * any line is translated.
     *
     * @param Collection<int, \stdClass> $items
     * @param Collection<array-key, Card> $cards
     * @param Collection<array-key, User> $actors
     */
    private function send(User $user, Collection $items, Collection $cards, Collection $actors): bool
    {
        $this->translator->setLocale((string) ($user->getPreference('locale') ?: $this->settings->get('default_locale')));
        $lines = $this->lines($user, $items, $cards, $actors);
        if (! $lines) {
            return false;
        }
        $boards = array_values(array_unique(array_column($lines, 'board')));
        try {
            $this->mailer->send(new DigestBlueprint($lines, count($boards) === 1 ? $boards[0] : null), $user);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[corkboard] digest to '.$user->id.' failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * What they may still see of it, as lines (the boards checked as this person, now).
     *
     * @param Collection<int, \stdClass> $items
     * @param Collection<array-key, Card> $cards
     * @param Collection<array-key, User> $actors
     * @return list<array{text: string, url: string, board: string}>
     */
    private function lines(User $user, Collection $items, Collection $cards, Collection $actors): array
    {
        $boards = Board::whereVisibleTo($user)->whereIn('id', $items->pluck('board_id')->unique())->get()->keyBy('id');

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
