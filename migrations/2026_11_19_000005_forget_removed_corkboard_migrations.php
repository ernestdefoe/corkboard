<?php

use Flarum\Database\MigrationRepositoryInterface;
use Illuminate\Database\Schema\Builder;

/*
 * Two of Corkboard 1.0.0's migration files are gone: 2026_11_13_000000_create_corkboard_tables
 * (its tables are now one file each, 2026_11_12_2300NN, which skip a table that exists and drop
 * it on the way down) and 2026_11_13_000001_add_corkboard_feed_token_to_users (its column moved
 * to corkboard_feed_tokens in 2026_11_19_000004). A forum that ran them still has their rows in
 * the migrations table, and Flarum's rollback (purging the extension) stops at a row whose file
 * is missing. So those rows are forgotten, through core's own migration repository; a fresh
 * install never had them. (A migration closure gets no injection, so the repository is resolved.)
 */
return [
    'up' => function (Builder $schema) {
        $repository = resolve(MigrationRepositoryInterface::class);
        foreach (['2026_11_13_000000_create_corkboard_tables', '2026_11_13_000001_add_corkboard_feed_token_to_users'] as $file) {
            $repository->delete($file, 'ernestdefoe-corkboard');
        }
    },
    'down' => function (Builder $schema) {
        // Nothing to put back: the files those rows named no longer exist, and what they made is
        // owned (and dropped on the way down) by the migrations that replaced them.
    },
];
