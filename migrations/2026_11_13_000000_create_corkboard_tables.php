<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * 🚨 No hand-named indexes or keys: Laravel doesn't prefix explicit names, so two forums sharing
 * one database would collide. Generated names carry the prefix.
 *
 * Counters are unsigned: a decrement below zero is an out-of-range error on MySQL, never a
 * negative, so every decrement goes through Counters::down().
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('corkboard_boards', function (Blueprint $table) {
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

        $schema->create('corkboard_columns', function (Blueprint $table) {
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

        $schema->create('corkboard_cards', function (Blueprint $table) {
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

        $schema->create('corkboard_labels', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('board_id');
            $table->string('name', 40)->default('');
            $table->string('color', 9);
            $table->integer('position')->default(0);

            $table->index('board_id');
            $table->foreign('board_id')->references('id')->on('corkboard_boards')->cascadeOnDelete();
        });

        $schema->create('corkboard_card_label', function (Blueprint $table) {
            $table->unsignedInteger('card_id');
            $table->unsignedInteger('label_id');

            $table->primary(['card_id', 'label_id']);
            $table->index('label_id');
            $table->foreign('card_id')->references('id')->on('corkboard_cards')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('corkboard_labels')->cascadeOnDelete();
        });

        $schema->create('corkboard_assignees', function (Blueprint $table) {
            $table->unsignedInteger('card_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('assigned_by')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->primary(['card_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('card_id')->references('id')->on('corkboard_cards')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->create('corkboard_watchers', function (Blueprint $table) {
            $table->unsignedInteger('card_id');
            $table->unsignedInteger('user_id');
            // false: they switched it off, so commenting again doesn't switch it back on.
            $table->boolean('watching')->default(true);

            $table->primary(['card_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('card_id')->references('id')->on('corkboard_cards')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->create('corkboard_checklist', function (Blueprint $table) {
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

        $schema->create('corkboard_comments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('card_id');
            // Null once the author's account is erased: the comment stays, as "Deleted user".
            $table->unsignedInteger('user_id')->nullable();
            $table->mediumText('content');
            $table->dateTime('edited_at')->nullable();
            $table->timestamps();

            $table->index(['card_id', 'id']);
            $table->foreign('card_id')->references('id')->on('corkboard_cards')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        $schema->create('corkboard_members', function (Blueprint $table) {
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

        $schema->create('corkboard_activity', function (Blueprint $table) {
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

        $schema->create('corkboard_pins', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('board_id');
            $table->integer('position')->default(0);

            $table->primary(['user_id', 'board_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('corkboard_boards')->cascadeOnDelete();
        });

        // Emails wait here a few minutes, so twelve assignments become one email.
        $schema->create('corkboard_outbox', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('board_id');
            $table->unsignedInteger('card_id')->nullable();
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('type', 24);
            $table->dateTime('created_at')->nullable();

            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('corkboard_boards')->cascadeOnDelete();
            $table->foreign('card_id')->references('id')->on('corkboard_cards')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        foreach (['outbox', 'pins', 'activity', 'members', 'comments', 'checklist', 'watchers', 'assignees', 'card_label', 'labels', 'cards', 'columns', 'boards'] as $table) {
            $schema->dropIfExists('corkboard_'.$table);
        }
    },
];
