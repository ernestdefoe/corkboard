<?php

use Flarum\Database\MigrationRepositoryInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Tidies up after Corkboard 1.0.0. A fresh install has none of this and the up() does nothing.
 *
 * 1. 1.0.0 kept each member's calendar feed link in users.corkboard_feed_token: the links move to
 *    corkboard_feed_tokens (made just before this) and the column goes. It can run again after
 *    stopping halfway (MySQL can't roll back schema changes): a link already moved is skipped.
 * 2. Two of 1.0.0's migration files are gone: 2026_11_13_000000_create_corkboard_tables (its
 *    tables are now one file each, 2026_11_12_2300NN) and 2026_11_13_000001_add_corkboard_feed_
 *    token_to_users (step 1 undoes it). Their rows stay in the migrations table, and Flarum's
 *    rollback (purging the extension) stops at a row whose file is missing, so they are forgotten
 *    through core's migration repository. So is 2026_11_19_000005_forget_removed_corkboard_
 *    migrations, the earlier name of step 2, for a forum that ran a build between releases.
 *    (A migration closure gets no injection, so the repository is resolved.)
 */
return [
    'up' => function (Builder $schema) {
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

        $repository = resolve(MigrationRepositoryInterface::class);
        $retired = ['2026_11_13_000000_create_corkboard_tables', '2026_11_13_000001_add_corkboard_feed_token_to_users', '2026_11_19_000005_forget_removed_corkboard_migrations'];
        foreach ($retired as $file) {
            $repository->delete($file, 'ernestdefoe-corkboard');
        }
    },
    'down' => function (Builder $schema) {
        // Nothing to undo, on purpose. Rolling Corkboard back means removing it: the feed links go
        // with corkboard_feed_tokens (its own migration drops it), and putting back the users
        // column or the forgotten rows would bring back exactly what this tidied away, rows whose
        // files no longer exist and that would stop the rollback.
    },
];
