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
return Migration::createTableIfNotExists('corkboard_cards', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('board_id');
    $table->unsignedInteger('column_id');
    $table->integer('position')->default(0);
    $table->string('title', 160);
    // The formatter's XML, like a post's content.
    $table->mediumText('description')->nullable();
    // none | low | normal | high | urgent
    $table->string('priority', 8)->default('none');
    $table->dateTime('due_at')->nullable();
    $table->boolean('due_all_day')->default(false);
    $table->dateTime('due_notified_at')->nullable();
    $table->dateTime('overdue_notified_at')->nullable();
    $table->dateTime('completed_at')->nullable();
    $table->unsignedInteger('completed_by')->nullable();
    $table->dateTime('archived_at')->nullable();
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    $table->string('cover_color', 9)->nullable();
    $table->unsignedInteger('comment_count')->default(0);
    $table->unsignedInteger('checklist_total')->default(0);
    $table->unsignedInteger('checklist_done')->default(0);
    $table->timestamps();

    $table->index(['board_id', 'column_id', 'position']);
    $table->index(['due_at', 'completed_at', 'archived_at']);
    $table->index('discussion_id');
    $table->foreign('board_id')->references('id')->on('corkboard_boards')->cascadeOnDelete();
    $table->foreign('column_id')->references('id')->on('corkboard_columns')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
});
