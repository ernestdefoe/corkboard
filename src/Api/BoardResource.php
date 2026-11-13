<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Board;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/** The `corkboard-boards` JSON:API type, for notifications about a whole board (see CardResource). @extends AbstractDatabaseResource<Board> */
class BoardResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'corkboard-boards';
    }

    public function model(): string
    {
        return Board::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title'),
            Schema\Str::make('slug'),
        ];
    }
}
