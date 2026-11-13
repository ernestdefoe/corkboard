<?php

namespace ErnestDefoe\Corkboard;

/**
 * The templates every forum has. Their names and columns are translated; a board started from
 * one gets these columns (the last one is the "done" column) and the six default labels.
 */
class Templates
{
    /** key => column keys; the last column counts as done */
    public const BUILT_IN = [
        'simple' => ['todo', 'doing', 'done'],
        'event' => ['ideas', 'booked', 'volunteer', 'ready', 'done'],
        'content' => ['pitches', 'writing', 'editing', 'scheduled', 'published'],
        'raid' => ['recruit', 'gear', 'strategy', 'ready'],
    ];

    /** @return list<array{title: string, isDone: bool}> */
    public static function columns(string $key, callable $t): array
    {
        $keys = self::BUILT_IN[$key] ?? self::BUILT_IN['simple'];
        $out = [];
        foreach ($keys as $i => $column) {
            $out[] = ['title' => $t('ernestdefoe-corkboard.lib.template.column.'.$column), 'isDone' => $i === count($keys) - 1];
        }

        return $out;
    }
}
