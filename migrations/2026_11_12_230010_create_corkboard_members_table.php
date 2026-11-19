<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Part of Corkboard's schema, one table per file (they run in name order, so every foreign key
 * points at a table made before it). A forum that installed Corkboard before the split already
 * has the table: createTableIfNotExists leaves it alone.
 *
 * 🚨 No hand-named indexes or keys: Laravel doesn't prefix explicit names, so two forums sharing
 * one database would collide. Generated names carry the prefix.
 */
return Migration::createTableIfNotExists('corkboard_members', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('board_id');
    // One of the two.
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('group_id')->nullable();
    // viewer | commenter | editor | manager
    $table->string('role', 10)->default('editor');
    $table->dateTime('created_at')->nullable();

    $table->index('board_id');
    $table->index('user_id');
    $table->index('group_id');
    $table->foreign('board_id')->references('id')->on('corkboard_boards')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
});
