<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A drop reads its column in position order; My cards and erasure read a member's cards.
 * PostgreSQL and SQLite don't index foreign keys, so they get these. MySQL already indexes
 * column_id and user_id for their foreign keys (and would drop its own once ours covered them,
 * leaving down() unable to remove ours), so it's left as it is. Generated, prefixed names.
 */
$needed = fn (Builder $schema) => ! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

return [
    'up' => function (Builder $schema) use ($needed) {
        if ($needed($schema)) {
            $schema->table('corkboard_cards', function (Blueprint $table) {
                $table->index(['column_id', 'position']);
                $table->index('user_id');
            });
        }
    },
    'down' => function (Builder $schema) use ($needed) {
        if ($needed($schema)) {
            $schema->table('corkboard_cards', function (Blueprint $table) {
                $table->dropIndex(['column_id', 'position']);
                $table->dropIndex(['user_id']);
            });
        }
    },
];
