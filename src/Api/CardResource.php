<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Card;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/**
 * The `corkboard-cards` JSON:API type, only so notifications can carry their card (a subject
 * model without a resource breaks every notification list). No endpoints: Corkboard's own API
 * serves cards. Notifications are already limited to cards the viewer may see.
 *
 * @extends AbstractDatabaseResource<Card>
 */
class CardResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'corkboard-cards';
    }

    public function model(): string
    {
        return Card::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title'),
            Schema\Integer::make('boardId')->property('board_id'),
            Schema\Str::make('boardTitle')->get(fn (Card $card) => $card->board->title),
            Schema\Str::make('boardSlug')->get(fn (Card $card) => $card->board->slug),
        ];
    }
}
