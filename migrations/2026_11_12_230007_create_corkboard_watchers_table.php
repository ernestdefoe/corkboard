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
return Migration::createTableIfNotExists('corkboard_watchers', function (Blueprint $table) {
    $table->unsignedInteger('card_id');
    $table->unsignedInteger('user_id');
    // false: they switched it off, so commenting again doesn't switch it back on.
    $table->boolean('watching')->default(true);

    $table->primary(['card_id', 'user_id']);
    $table->index('user_id');
    $table->foreign('card_id')->references('id')->on('corkboard_cards')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
