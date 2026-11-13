<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Member;
use ErnestDefoe\Corkboard\Presenter;
use ErnestDefoe\Corkboard\Team;
use Flarum\Group\Group;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /corkboard/boards/{id}/members   userId or groupId, role
 * PATCH  /corkboard/members/{id}          role
 * DELETE /corkboard/members/{id}          (managers, or someone leaving)
 * GET    /corkboard/boards/{id}/activity?before=
 * GET    /corkboard/people?q=&groups=1    people (and groups) to add to a team or a card
 *
 * Team writes answer with the whole board, which is what the settings page redraws.
 */
class TeamController extends Controller
{
    public function __construct(
        private readonly Team $team,
        private readonly Presenter $presenter,
        private readonly ActivityPresenter $activity,
    ) {
    }

    /** @return array<string, mixed> */
    protected function add(ServerRequestInterface $request, User $actor): array
    {
        $board = $this->board($request, $actor);
        $this->team->add($board, $actor, $this->body($request));

        return $this->presenter->board($board, $actor);
    }

    /** @return array<string, mixed> */
    protected function role(ServerRequestInterface $request, User $actor): array
    {
        $member = Member::query()->findOrFail($this->id($request));
        $board = $this->board($request, $actor, $member->board_id);
        $this->team->update($member, $board, $actor, (string) ($this->body($request)['role'] ?? ''));

        return $this->presenter->board($board, $actor);
    }

    /** @return array<string, mixed>|null */
    protected function remove(ServerRequestInterface $request, User $actor): ?array
    {
        $member = Member::query()->findOrFail($this->id($request));
        $board = $this->board($request, $actor, $member->board_id);
        $this->team->remove($member, $board, $actor);
        // Someone who just left may not see the board any more.
        $visible = \ErnestDefoe\Corkboard\Board::whereVisibleTo($actor)->whereKey($board->id)->exists();

        return $visible ? $this->presenter->board($board, $actor) : null;
    }

    /** @return array<string, mixed> */
    protected function activity(ServerRequestInterface $request, User $actor): array
    {
        return $this->activity->page($this->board($request, $actor), null, $this->param($request, 'before') ?: null);
    }

    /** @return array<string, mixed> */
    protected function people(ServerRequestInterface $request, User $actor): array
    {
        $actor->assertRegistered();
        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if ($q === '') {
            return ['users' => [], 'groups' => []];
        }
        $like = addcslashes($q, '%_\\').'%';
        $users = User::whereVisibleTo($actor)
            ->where(function ($w) use ($like) {
                $w->where('username', 'like', $like);
                if (resolve(\Flarum\Extension\ExtensionManager::class)->isEnabled('flarum-nicknames')) {
                    $w->orWhere('nickname', 'like', $like);
                }
            })
            ->orderBy('username')->limit(8)->get();

        $groups = [];
        if (! empty($request->getQueryParams()['groups'])) {
            $groups = Group::query()->where('id', '<>', Group::GUEST_ID)
                ->where(fn ($w) => $w->where('name_singular', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('name_plural', 'like', '%'.addcslashes($q, '%_\\').'%'))
                ->where('is_hidden', false)
                ->limit(5)->get()
                ->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name_plural, 'color' => $g->color, 'icon' => $g->icon])->values()->all();
        }

        return ['users' => $users->map(fn (User $u) => $this->presenter->user($u))->values()->all(), 'groups' => $groups];
    }
}
