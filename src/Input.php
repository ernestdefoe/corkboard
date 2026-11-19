<?php

namespace ErnestDefoe\Corkboard;

/** What the services do to text and colours people type, in one place. */
final class Input
{
    /**
     * A name on one line: runs of whitespace become one space, trimmed; empty or longer than $max
     * is a refusal (the locale key $why, on $field).
     */
    public static function line(mixed $value, int $max, string $why, string $field = 'title'): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($text === '' || mb_strlen($text) > $max) {
            throw Refusal::because($why, $field);
        }

        return $text;
    }

    /** A #rrggbb colour, or null for anything else. */
    public static function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
    }
}
