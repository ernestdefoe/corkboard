<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// The flood check counts a member's recent changes (Throttle).
return [
    'up' => function (Builder $schema) {
        $schema->table('corkboard_activity', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('corkboard_activity', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
        });
    },
];
