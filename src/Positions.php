<?php

namespace ErnestDefoe\Corkboard;

use Illuminate\Database\ConnectionInterface;

/**
 * Order by integer positions with gaps: a drop between 2048 and 3072 lands on 2560, one row
 * written. When a gap closes the list is renumbered in one CASE update. The rows are locked while
 * the position is worked out, so two people dropping into one column at once never collide.
 */
class Positions
{
    public const GAP = 1024;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * Where an item goes after $afterId (null: first) in a list of $table rows where $scope = $scopeId.
     */
    public function place(string $table, string $scope, int $scopeId, ?int $afterId, ?int $except = null): int
    {
        $rows = $this->rows($table, $scope, $scopeId, $except);
        [$prev, $next] = $this->neighbours($rows, $afterId);

        if ($prev !== null && $next !== null && $next - $prev < 2) {
            $this->renumber($table, array_keys($rows));
            $rows = $this->rows($table, $scope, $scopeId, $except);
            [$prev, $next] = $this->neighbours($rows, $afterId);
        }

        return match (true) {
            $prev === null && $next === null => 0,
            $prev === null => $next - self::GAP,
            $next === null => $prev + self::GAP,
            default => intdiv($prev + $next, 2),
        };
    }

    /** At the end of the list. */
    public function last(string $table, string $scope, int $scopeId): int
    {
        $max = $this->db->table($table)->where($scope, $scopeId)->max('position');

        return $max === null ? 0 : (int) $max + self::GAP;
    }

    /**
     * Numbers $ids GAP apart, in this order, in one statement.
     *
     * @param list<int> $ids
     */
    public function renumber(string $table, array $ids): void
    {
        if (! $ids) {
            return;
        }
        $case = 'CASE '.$this->db->getQueryGrammar()->wrap('id');
        foreach (array_values($ids) as $i => $id) {
            $case .= ' WHEN '.(int) $id.' THEN '.(($i + 1) * self::GAP);
        }
        $this->db->table($table)->whereIn('id', $ids)->update(['position' => $this->db->raw($case.' END')]);
    }

    /** @return array<int, int> id => position, in order */
    private function rows(string $table, string $scope, int $scopeId, ?int $except): array
    {
        return $this->db->table($table)
            ->where($scope, $scopeId)
            ->when($except, fn ($q) => $q->where('id', '<>', $except))
            ->orderBy('position')->orderBy('id')
            ->lockForUpdate()
            ->pluck('position', 'id')
            ->map(fn ($p) => (int) $p)
            ->all();
    }

    /**
     * @param array<int, int> $rows
     * @return array{int|null, int|null}
     */
    private function neighbours(array $rows, ?int $afterId): array
    {
        $ids = array_keys($rows);
        if ($afterId === null || ! isset($rows[$afterId])) {
            return [null, $ids ? $rows[$ids[0]] : null];
        }
        $i = array_search($afterId, $ids, true);
        $next = $ids[$i + 1] ?? null;

        return [$rows[$afterId], $next === null ? null : $rows[$next]];
    }
}
