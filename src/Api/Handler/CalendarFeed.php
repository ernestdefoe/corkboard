<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use Carbon\Carbon;
use ErnestDefoe\Corkboard\Card;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/corkboard/feed/{token}   the calendar: due dates of the cards assigned to the link's owner,
 * as they may see them. No sign-in: the 40-character token is the key.
 *
 * 🚨 No ".ics" on the route: nginx serves anything ending in a file extension from disk and PHP
 * never sees it. Calendar apps don't need the extension; the Content-Type says what it is.
 */
class CalendarFeed extends Handler
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly UrlGenerator $url,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $token = (string) ($request->getAttribute('routeParameters', [])['token'] ?? '');
        $owner = strlen($token) === 40 ? $this->db->table('corkboard_feed_tokens')->where('token', $token)->value('user_id') : null;
        $user = $owner ? User::query()->find($owner) : null;
        if (! $user) {
            return new Response\TextResponse('Not found', 404);
        }

        $cards = Card::whereVisibleTo($user)
            ->whereIn('corkboard_cards.id', fn ($a) => $a->select('card_id')->from('corkboard_assignees')->where('user_id', $user->id))
            ->whereNull('corkboard_cards.archived_at')
            ->whereNotNull('corkboard_cards.due_at')
            ->where('corkboard_cards.due_at', '>=', Carbon::now()->subDays(60))
            ->with('board')->orderBy('corkboard_cards.due_at')->limit(500)->get();

        $host = parse_url($this->url->to('forum')->base(), PHP_URL_HOST) ?: 'forum';
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Corkboard//Flarum//EN', 'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.$this->escape($this->settings->get('forum_title').' · Corkboard'),
        ];
        foreach ($cards as $card) {
            if ($card->board->deleted_at !== null) {
                continue;
            }
            $due = $card->due_at->copy()->utc();
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:corkboard-card-'.$card->id.'@'.$host;
            $lines[] = 'DTSTAMP:'.Carbon::now()->utc()->format('Ymd\THis\Z');
            if ($card->due_all_day) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$due->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$due->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$due->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$due->copy()->addMinutes(30)->format('Ymd\THis\Z');
            }
            $lines[] = 'SUMMARY:'.$this->escape(($card->completed_at ? '✓ ' : '').$card->title);
            $lines[] = 'DESCRIPTION:'.$this->escape($card->board->title);
            $lines[] = 'URL:'.$this->url->to('forum')->route('corkboard.board', ['id' => $card->board->slugged()]).'?card='.$card->id;
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        $body = implode("\r\n", array_map(fn ($l) => $this->fold($l), $lines))."\r\n";

        return new Response\TextResponse($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n'], $text);
    }

    /** Lines longer than 75 octets are folded (RFC 5545). */
    private function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            // Never inside a UTF-8 character.
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
        }

        return $out.$line;
    }
}
