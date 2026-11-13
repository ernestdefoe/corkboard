<?php

namespace ErnestDefoe\Corkboard\Notification;

use ErnestDefoe\Corkboard\Board;
use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/** A Corkboard alert about a whole board (see CardBlueprint for why data carries a ref). */
abstract class BoardBlueprint implements BlueprintInterface
{
    public function __construct(public Board $board, public ?User $actor, public int $ref)
    {
    }

    public function getFromUser(): ?User
    {
        return $this->actor;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->board;
    }

    public function getData(): mixed
    {
        return ['r' => $this->ref];
    }

    public static function getSubjectModel(): string
    {
        return Board::class;
    }
}
