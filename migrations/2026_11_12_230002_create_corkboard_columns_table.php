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
return Migration::createTableIfNotExists('corkboard_columns', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('board_id');
    $table->string('title', 80);
    $table->string('color', 9)->nullable();
    $table->integer('position')->default(0);
    $table->unsignedInteger('wip_limit')->nullable();
    // Cards moved here count as done (Renown, widgets, the ✓ on the card).
    $table->boolean('is_done')->default(false);
    $table->unsignedInteger('auto_archive_days')->nullable();
    $table->timestamps();

    $table->index(['board_id', 'position']);
    $table->foreign('board_id')->references('id')->on('corkboard_boards')->cascadeOnDelete();
});
