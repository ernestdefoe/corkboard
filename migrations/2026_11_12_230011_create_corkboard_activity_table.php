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
return Migration::createTableIfNotExists('corkboard_activity', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('board_id');
    $table->unsignedInteger('card_id')->nullable();
    $table->unsignedInteger('user_id')->nullable();
    $table->string('type', 24);
    // Ids only: names and titles are read when it's shown.
    $table->text('data')->nullable();
    $table->dateTime('created_at')->nullable();

    $table->index(['board_id', 'id']);
    $table->index(['card_id', 'id']);
    $table->foreign('board_id')->references('id')->on('corkboard_boards')->cascadeOnDelete();
    $table->foreign('card_id')->references('id')->on('corkboard_cards')->nullOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
