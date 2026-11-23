<?php

namespace App\Domain\Shared\History;

/**
 * Field-level comparison of two canonical snapshots (field => string|null).
 * Each history service keeps its own snapshot shape and History texts.
 */
final class SnapshotDiff
{
    /**
     * A field missing from one side counts as null; fields keep first-seen order.
     *
     * @param  array<string, string|null>  $previous
     * @param  array<string, string|null>  $current
     * @return list<array{field_name: string, previous_value: string|null, new_value: string|null}>
     */
    public static function diff(array $previous, array $current): array
    {
        $changes = [];

        foreach (array_keys($previous + $current) as $field) {
            $previousValue = $previous[$field] ?? null;
            $newValue = $current[$field] ?? null;

            if ($previousValue === $newValue) {
                continue;
            }

            $changes[] = [
                'field_name' => $field,
                'previous_value' => $previousValue,
                'new_value' => $newValue,
            ];
        }

        return $changes;
    }
}
