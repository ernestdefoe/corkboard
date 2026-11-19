<?php

namespace ErnestDefoe\Corkboard\Mail;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Access;
use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Card;
use ErnestDefoe\Corkboard\Notification\DigestBlueprint;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationMailer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        private readonly Access $access,
    ) {
    }

    /**
     * A fixed number of queries per run: the waiting rows, the people with their groups, the
     * boards, their team rows, the cards and the actors named, the clean-up. Who may still see
     * each board is worked out per board (Access::audience); only someone no team row lets in
     * costs a read of their permissions.
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

        $users = User::query()->whereIn('id', $ready->keys())->with('groups')->get()->keyBy('id');
        $seen = $this->audiences($all, $users);
        $cards = Card::query()->whereIn('id', $all->pluck('card_id')->filter()->unique())->get()->keyBy('id');
        $actors = User::query()->whereIn('id', $all->pluck('actor_id')->filter()->unique())->get()->keyBy('id');
        $locale = $this->translator->getLocale();
        $sent = 0;
        try {
            foreach ($all->groupBy('user_id') as $userId => $items) {
                $user = $users[$userId] ?? null;
                if ($user && $user->is_email_confirmed && $this->send($user, $items, $seen, $cards, $actors)) {
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
        $at = $this->db->getQueryGrammar()->wrap('created_at');
        $rows = $this->db->table('corkboard_outbox')->groupBy('user_id')
            ->select('user_id')->selectRaw("min($at) as oldest, max($at) as newest")->get();
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
     * The boards in this batch, each with the ids of the recipients who may still see it: the
     * boards and all their team rows in one query each, then Access::audience per board.
     *
     * @param Collection<int, \stdClass> $items
     * @param Collection<array-key, User> $users
     * @return array<int, array{board: Board, who: array<int, true>}>
     */
    private function audiences(Collection $items, Collection $users): array
    {
        $boards = Board::query()->whereIn('id', $items->pluck('board_id')->unique())->get();
        $rows = $this->db->table('corkboard_members')->whereIn('board_id', $boards->pluck('id'))->get(['board_id', 'user_id', 'group_id'])->groupBy('board_id');
        $seen = [];
        foreach ($boards as $board) {
            $waiting = $users->only($items->where('board_id', $board->id)->pluck('user_id')->unique()->all());
            $who = $this->access->audience($board, new EloquentCollection($waiting->values()->all()), $rows[$board->id] ?? collect());
            $seen[(int) $board->id] = ['board' => $board, 'who' => array_fill_keys($who->pluck('id')->map(fn ($id) => (int) $id)->all(), true)];
        }

        return $seen;
    }

    /**
     * One person's email, written in their language (or the forum's): the locale is set before
     * any line is translated.
     *
     * @param Collection<int, \stdClass> $items
     * @param array<int, array{board: Board, who: array<int, true>}> $seen
     * @param Collection<array-key, Card> $cards
     * @param Collection<array-key, User> $actors
     */
    private function send(User $user, Collection $items, array $seen, Collection $cards, Collection $actors): bool
    {
        $this->translator->setLocale((string) ($user->getPreference('locale') ?: $this->settings->get('default_locale')));
        $lines = $this->lines($user, $items, $seen, $cards, $actors);
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
     * What they may still see of it, as lines (each board's audience worked out now).
     *
     * @param Collection<int, \stdClass> $items
     * @param array<int, array{board: Board, who: array<int, true>}> $seen
     * @param Collection<array-key, Card> $cards
     * @param Collection<array-key, User> $actors
     * @return list<array{text: string, url: string, board: string}>
     */
    private function lines(User $user, Collection $items, array $seen, Collection $cards, Collection $actors): array
    {
        $lines = [];
        foreach ($items as $item) {
            $board = isset($seen[(int) $item->board_id]['who'][(int) $user->id]) ? $seen[(int) $item->board_id]['board'] : null;
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
