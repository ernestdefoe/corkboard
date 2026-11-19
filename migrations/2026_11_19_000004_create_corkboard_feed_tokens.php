<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A member's private calendar feed link, one per person (Api\Handler\FeedToken). Corkboard 1.0.0
 * kept it in a column on users: 2026_11_19_000005_upgrade_from_corkboard_1_0 moves those here.
 */
return Migration::createTableIfNotExists('corkboard_feed_tokens', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->string('token', 40)->unique();
    $table->timestamp('created_at')->nullable();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
