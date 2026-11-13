<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Card;
use Flarum\Api\Context;
use Flarum\Discussion\Discussion;
use WeakMap;

/**
 * The "On Spring Meetup 2027 · Doing" chip on a discussion: the cards linked to it that this
 * viewer may see. A deferred field: every discussion in the answer queues its id, and the first
 * one resolved loads them all in one query (none when no discussion in the page is linked from
 * a board the viewer can see, the common case: one query that finds nothing).
 */
class DiscussionCards
{
    /**
     * Per request (a new instance is resolved for every discussion, so the batch is static).
     *
     * @var WeakMap<object, array{ids: array<int, true>, rows: array<int, list<array<string, mixed>>>|null}>|null
     */
    private static ?WeakMap $batches = null;

    public function __invoke(Discussion $discussion, Context $context): \Closure
    {
        $request = $context->request;
        self::$batches ??= new WeakMap();
        $batch = self::$batches[$request] ?? ['ids' => [], 'rows' => null];
        $batch['ids'][$discussion->id] = true;
        self::$batches[$request] = $batch;

        return function () use ($discussion, $context, $request) {
            $batch = self::$batches[$request];
            if ($batch['rows'] === null) {
                $batch['rows'] = [];
                $rows = Card::whereVisibleTo($context->getActor())
                    ->whereIn('corkboard_cards.discussion_id', array_keys($batch['ids']))
                    ->whereNull('corkboard_cards.archived_at')
                    ->join('corkboard_boards', 'corkboard_boards.id', '=', 'corkboard_cards.board_id')
                    ->join('corkboard_columns', 'corkboard_columns.id', '=', 'corkboard_cards.column_id')
                    ->whereNull('corkboard_boards.deleted_at')
                    ->orderBy('corkboard_cards.id')
                    ->limit(200)
                    ->get(['corkboard_cards.id', 'corkboard_cards.discussion_id', 'corkboard_cards.board_id', 'corkboard_boards.title as board_title', 'corkboard_boards.slug as board_slug', 'corkboard_columns.title as column_title', 'corkboard_boards.color as board_color']);
                foreach ($rows as $row) {
                    $batch['rows'][(int) $row->discussion_id][] = [
                        'cardId' => (int) $row->id,
                        'boardId' => (int) $row->board_id,
                        'board' => $row->getAttribute('board_title'),
                        'slug' => $row->getAttribute('board_slug'),
                        'column' => $row->getAttribute('column_title'),
                        'color' => $row->getAttribute('board_color'),
                    ];
                }
                self::$batches[$request] = $batch;
            }

            return $batch['rows'][$discussion->id] ?? [];
        };
    }
}
