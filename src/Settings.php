<?php

namespace ErnestDefoe\Corkboard;

use Flarum\Settings\SettingsRepositoryInterface;

class Settings
{
    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    /** @return list<string> the visibility modes the admin offers (at least one). */
    public function visibilities(): array
    {
        $list = json_decode((string) $this->settings->get('ernestdefoe-corkboard.visibilities'), true);
        $list = is_array($list) ? array_values(array_intersect(Board::VISIBILITIES, $list)) : [];

        return $list ?: Board::VISIBILITIES;
    }

    public function defaultVisibility(): string
    {
        $v = (string) $this->settings->get('ernestdefoe-corkboard.default_visibility');
        $allowed = $this->visibilities();

        return in_array($v, $allowed, true) ? $v : (in_array('team', $allowed, true) ? 'team' : $allowed[0]);
    }

    /** How long before a due date the "due soon" alert goes out. */
    public function dueSoonHours(): int
    {
        return max(1, min(24 * 7, (int) ($this->settings->get('ernestdefoe-corkboard.due_soon_hours') ?: 24)));
    }

    public function minPosts(): int
    {
        return max(0, (int) $this->settings->get('ernestdefoe-corkboard.min_posts'));
    }

    /** Teams up to this size get live updates on private boards (one push per person). */
    public function maxFanout(): int
    {
        return 50;
    }
}
