<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A member's private calendar feed link, keyed on the user. Corkboard 1.0.0 kept it in a column
 * on users: forums that ran that migration get their links moved here and the column dropped.
 */
return [
    'up' => function (Builder $schema) {
        // MySQL can't roll back schema changes: a run that stopped halfway must be able to run again.
        if (! $schema->hasTable('corkboard_feed_tokens')) {
            $schema->create('corkboard_feed_tokens', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->string('token', 40)->unique();
                $table->timestamp('created_at')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        if ($schema->hasColumn('users', 'corkboard_feed_token')) {
            $db = $schema->getConnection();
            $db->table('corkboard_feed_tokens')->insertOrIgnoreUsing(
                ['user_id', 'token'],
                $db->table('users')->whereNotNull('corkboard_feed_token')->select(['id', 'corkboard_feed_token'])
            );
            $schema->table('users', function (Blueprint $table) {
                $table->dropUnique(['corkboard_feed_token']);
                $table->dropColumn('corkboard_feed_token');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('corkboard_feed_tokens');
    },
];
