<?php

namespace ErnestDefoe\Corkboard;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * ernestdefoe/projects, read straight from its tables (only when it's enabled): a board can
 * belong to a project its author or a co-author picks; the project page shows that board's
 * progress to people who may see the board.
 */
class Projects
{
    public static function enabled(): bool
    {
        return resolve(Extensions::class)->projects();
    }

    /** @return array<string, mixed>|null */
    public static function summary(ConnectionInterface $db, int $id, User $actor): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $project = $db->table('projects')->where('id', $id)->first(['id', 'title', 'slug', 'status', 'user_id']);
        if (! $project || ($project->status !== 'published' && ! self::isAuthor($db, $id, $actor) && ! $actor->hasPermission('corkboard.moderate'))) {
            return null;
        }

        return ['id' => (int) $project->id, 'title' => $project->title, 'slug' => $project->slug];
    }

    public static function isAuthor(ConnectionInterface $db, int $id, User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }
        if ((int) $db->table('projects')->where('id', $id)->value('user_id') === (int) $actor->id) {
            return true;
        }

        return $db->getSchemaBuilder()->hasTable('project_authors')
            && $db->table('project_authors')->where('project_id', $id)->where('user_id', $actor->id)->exists();
    }

    /** @return list<array{id: int, title: string}> projects this person may attach a board to */
    public static function mine(ConnectionInterface $db, User $actor): array
    {
        if (! self::enabled() || $actor->isGuest()) {
            return [];
        }
        $q = $db->table('projects')->where('user_id', $actor->id);
        if ($db->getSchemaBuilder()->hasTable('project_authors')) {
            $q->orWhereIn('id', $db->table('project_authors')->where('user_id', $actor->id)->select('project_id'));
        }

        return $q->orderBy('title')->limit(100)->get(['id', 'title'])->map(fn ($p) => ['id' => (int) $p->id, 'title' => $p->title])->all();
    }
}
