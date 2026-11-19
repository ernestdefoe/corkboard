<?php

namespace ErnestDefoe\Corkboard;

use ErnestDefoe\Projects\Model\Project;
use Flarum\User\User;

/**
 * ernestdefoe/projects, through its own Project model (only once its Integration has switched it
 * on): a board can belong to a project its author or a co-author picks; the project page shows that
 * board's progress to people who may see the board.
 */
class Projects
{
    public function __construct(private readonly Extensions $extensions)
    {
    }

    public function enabled(): bool
    {
        return $this->extensions->projects();
    }

    /** @return array<string, mixed>|null the project a board shows, if this person may see it */
    public function summary(int $id, User $actor): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $project = Project::query()->find($id, ['id', 'title', 'slug', 'status', 'user_id']);
        if (! $project || (! $project->isPublished() && ! $this->isAuthor($id, $actor) && ! $actor->hasPermission('corkboard.moderate'))) {
            return null;
        }

        return ['id' => (int) $project->id, 'title' => $project->title, 'slug' => $project->slug];
    }

    public function isAuthor(int $id, User $actor): bool
    {
        if (! $this->enabled() || $actor->isGuest()) {
            return false;
        }

        return Project::query()->whereKey($id)
            ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhereHas('coAuthors', fn ($a) => $a->where('user_id', $actor->id)))
            ->exists();
    }

    /** @return list<array{id: int, title: string}> projects this person may attach a board to */
    public function mine(User $actor): array
    {
        if (! $this->enabled() || $actor->isGuest()) {
            return [];
        }

        return Project::query()
            ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhereHas('coAuthors', fn ($a) => $a->where('user_id', $actor->id)))
            ->orderBy('title')->limit(100)->get(['id', 'title'])
            ->map(fn (Project $p) => ['id' => (int) $p->id, 'title' => $p->title])->values()->all();
    }
}
