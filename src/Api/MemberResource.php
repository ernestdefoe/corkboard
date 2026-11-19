<?php

namespace ErnestDefoe\Corkboard\Api;

use ErnestDefoe\Corkboard\Board;
use ErnestDefoe\Corkboard\Member;
use ErnestDefoe\Corkboard\Presenter;
use ErnestDefoe\Corkboard\Team;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * `corkboard-members`: a board's team rows (a person or a group, with a role), which follow
 * their board.
 *
 * GET    /api/corkboard-members/{id}
 * POST   /api/corkboard-members        boardId, userId or groupId, role
 * PATCH  /api/corkboard-members/{id}   role
 * DELETE /api/corkboard-members/{id}   (managers, or someone leaving)
 *
 * Writes answer (view) with the whole board, which is what the settings page redraws.
 *
 * @extends Resource<Member>
 */
class MemberResource extends Resource
{
    public function __construct(
        private readonly Team $team,
        private readonly Presenter $presenter,
    ) {
    }

    public function type(): string
    {
        return 'corkboard-members';
    }

    public function model(): string
    {
        return Member::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $query->whereIn('corkboard_members.board_id', Board::whereVisibleTo($context->getActor())->select('corkboard_boards.id'));
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Show::make(),
            Endpoint\Create::make()->authenticated(),
            Endpoint\Update::make()->authenticated(),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('boardId')->property('board_id')->writableOnCreate()->requiredOnCreate(),
            Schema\Integer::make('userId')->property('user_id')->nullable()->writableOnCreate(),
            Schema\Integer::make('groupId')->property('group_id')->nullable()->writableOnCreate(),
            Schema\Str::make('role')->in(Member::ROLES)->writable(),

            self::view(fn (Member $member, Context $context) => $this->presenter->board(Find::board($context->getActor(), $member->board_id), $context->getActor())),
        ];
    }

    public function create(object $model, BaseContext $context): object
    {
        $in = self::input($context);
        $board = Find::board($context->getActor(), (int) $in['boardId']);
        unset($in['boardId']);

        return $this->team->add($board, $context->getActor(), $in);
    }

    public function update(object $model, BaseContext $context): object
    {
        $role = self::input($context)['role'] ?? '';

        return $this->team->update($model, Find::board($context->getActor(), $model->board_id), $context->getActor(), is_string($role) ? $role : '');
    }

    public function delete(object $model, BaseContext $context): void
    {
        $this->team->remove($model, Find::board($context->getActor(), $model->board_id), $context->getActor());
    }
}
