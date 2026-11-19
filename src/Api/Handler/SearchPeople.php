<?php

namespace ErnestDefoe\Corkboard\Api\Handler;

use ErnestDefoe\Corkboard\Extensions;
use ErnestDefoe\Corkboard\Presenter;
use Flarum\Group\Group;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/corkboard/people?q=&groups=1   people (and groups) to add to a team or a card */
class SearchPeople extends Handler
{
    public function __construct(
        private readonly Presenter $presenter,
        private readonly Extensions $extensions,
    ) {
    }

    protected function respond(ServerRequestInterface $request, User $actor): array
    {
        // Core's own rule for finding people by name.
        $actor->assertCan('searchUsers');
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if ($q === '') {
            return ['users' => [], 'groups' => []];
        }
        $like = addcslashes($q, '%_\\').'%';
        $users = User::whereVisibleTo($actor)
            ->where(function ($w) use ($like) {
                $w->whereLike('username', $like);
                if ($this->extensions->enabled('flarum-nicknames')) {
                    $w->orWhereLike('nickname', $like);
                }
            })
            ->orderBy('username')->limit(8)->get();

        $groups = [];
        if (! empty($request->getQueryParams()['groups'])) {
            $groups = Group::query()->where('id', '<>', Group::GUEST_ID)
                ->where(fn ($w) => $w->whereLike('name_singular', $like)->orWhereLike('name_plural', $like))
                ->where('is_hidden', false)
                ->limit(5)->get()
                ->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name_plural, 'color' => $g->color, 'icon' => $g->icon])->values()->all();
        }

        return ['users' => $users->map(fn (User $u) => $this->presenter->user($u))->values()->all(), 'groups' => $groups];
    }
}
