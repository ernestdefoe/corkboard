<?php

namespace ErnestDefoe\Corkboard\Scope;

use ErnestDefoe\Corkboard\Access;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Who sees a board. The one read rule: the API, the widgets, notifications, the discussion chip
 * and the calendar feed all go through it.
 *
 * - moderators: every board;
 * - public: anyone with `corkboard.view` (guests too when they have it);
 * - members: anyone signed in with `corkboard.view`;
 * - team: the owner and the people and groups on its team;
 * - private: the owner and the people on its team by name.
 */
class BoardVisibility
{
    public function __invoke(User $actor, Builder $query): void
    {
        if ($actor->hasPermission('corkboard.moderate')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor) {
            if ($actor->hasPermission('corkboard.view')) {
                $q->whereIn('corkboard_boards.visibility', $actor->isGuest() ? ['public'] : ['public', 'members']);
            } else {
                $q->whereRaw('1 = 0');
            }

            if ($actor->isGuest()) {
                return;
            }

            $groups = Access::groupIds($actor);
            $q->orWhere('corkboard_boards.owner_id', $actor->id)
                ->orWhereExists(fn (QueryBuilder $m) => $m->selectRaw('1')->from('corkboard_members')
                    ->whereColumn('corkboard_members.board_id', 'corkboard_boards.id')
                    ->where('corkboard_members.user_id', $actor->id))
                ->orWhere(fn (Builder $team) => $team->where('corkboard_boards.visibility', '<>', 'private')
                    ->whereExists(fn (QueryBuilder $m) => $m->selectRaw('1')->from('corkboard_members')
                        ->whereColumn('corkboard_members.board_id', 'corkboard_boards.id')
                        ->whereIn('corkboard_members.group_id', $groups)));
        });
    }
}
