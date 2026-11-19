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
return Migration::createTableIfNotExists('corkboard_checklist', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('card_id');
    $table->string('text', 300);
    $table->boolean('is_done')->default(false);
    $table->integer('position')->default(0);
    $table->unsignedInteger('user_id')->nullable();
    $table->timestamps();

    $table->index(['card_id', 'position']);
    $table->foreign('card_id')->references('id')->on('corkboard_cards')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
