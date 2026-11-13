<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// A member's private calendar feed of their cards' due dates.
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('users', 'corkboard_feed_token')) {
            $schema->table('users', function (Blueprint $table) {
                $table->string('corkboard_feed_token', 40)->nullable()->unique();
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('users', 'corkboard_feed_token')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropUnique(['corkboard_feed_token']);
                $table->dropColumn('corkboard_feed_token');
            });
        }
    },
];
