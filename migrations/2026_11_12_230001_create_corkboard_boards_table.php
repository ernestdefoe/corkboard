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
 *
 * Counters are unsigned: a decrement below zero is an out-of-range error on MySQL, never a
 * negative, so counts are recounted from the rows (Counters), never decremented.
 */
return Migration::createTableIfNotExists('corkboard_boards', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('owner_id')->nullable();
    $table->string('title', 160);
    $table->string('slug', 160);
    $table->text('description')->nullable();
    $table->string('color', 9)->default('#4d698e');
    // public | members | team | private
    $table->string('visibility', 10)->default('team');
    // active | completed | archived
    $table->string('status', 10)->default('active');
    $table->unsignedInteger('tag_id')->nullable();
    $table->unsignedInteger('project_id')->nullable();
    $table->boolean('is_template')->default(false);
    $table->unsignedInteger('card_count')->default(0);
    $table->unsignedInteger('done_count')->default(0);
    // Bumped on every write: the poll asks "still at N?".
    $table->unsignedInteger('version')->default(0);
    $table->dateTime('completed_at')->nullable();
    $table->dateTime('archived_at')->nullable();
    $table->dateTime('last_activity_at')->nullable();
    $table->dateTime('deleted_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'last_activity_at']);
    $table->index('owner_id');
    $table->index('project_id');
    $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
});
