<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * One team row per person and per group on a board, kept by the database (Team::add writes with
 * an upsert on these keys). A double-clicked "add" before 1.1 could leave two rows: the duplicates
 * go first, keeping each person's or group's highest role. NULLs never collide on MySQL,
 * PostgreSQL or SQLite, so a group row (user_id NULL) and a person row (group_id NULL) don't
 * clash. Generated index names only: they carry the table prefix.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $rank = ['viewer' => 1, 'commenter' => 2, 'editor' => 3, 'manager' => 4];
        foreach (['user_id', 'group_id'] as $who) {
            $twice = $db->table('corkboard_members')->whereNotNull($who)
                ->groupBy('board_id', $who)->havingRaw('count(*) > 1')->select(['board_id', $who])->get();
            foreach ($twice as $pair) {
                $rows = $db->table('corkboard_members')->where('board_id', $pair->board_id)->where($who, $pair->{$who})->orderBy('id')->get(['id', 'role']);
                $keep = $rows->sortByDesc(fn ($row) => ($rank[$row->role] ?? 0) * 1e9 - $row->id)->first();
                $db->table('corkboard_members')->whereIn('id', $rows->pluck('id')->reject(fn ($id) => $id === $keep->id))->delete();
            }
        }

        $schema->table('corkboard_members', function (Blueprint $table) {
            $table->unique(['board_id', 'user_id']);
            $table->unique(['board_id', 'group_id']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('corkboard_members', function (Blueprint $table) {
            $table->dropUnique(['board_id', 'user_id']);
            $table->dropUnique(['board_id', 'group_id']);
        });
    },
];
