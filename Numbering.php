<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Taxonomies;

/**
 * Pre/post-order numbers for a forest (Dietz 1982; Celko's nested sets): one depth-first walk gives each term the
 * number it is entered at (`lft_n`) and left at (`rgt_n`), so a term's subtree is exactly the terms whose `lft_n`
 * lies inside its own `[lft_n, rgt_n]`.
 *
 * Siblings, and roots, are walked by code in byte order (`strcmp`, then id) — the order SQL's binary comparison
 * gives, and a total one: PHP's `<=>` on strings compares numeric strings as numbers and the rest as text, which is
 * not transitive (`'10' < '1a' < '9' < '10'`), so the same tree could get different numbers. It always gets the same. A parent outside the set makes a root. Iterative, so depth costs no stack.
 */
final class Numbering
{
    private function __construct()
    {
    }

    /**
     * @param array<int, int|null> $parent_by_id every term, id => its parent's id (null: a root)
     * @param array<int, string>   $code_by_id   every term, id => code
     * @return array<int, array{lft_n: int, rgt_n: int, depth_n: int}>
     */
    public static function number(array $parent_by_id, array $code_by_id): array
    {
        $children = [];

        foreach ($parent_by_id as $id => $parent_id) {
            $key = $parent_id !== null && array_key_exists($parent_id, $parent_by_id) ? $parent_id : 0;   // a root's own value is null
            $children[$key][] = $id;
        }

        foreach ($children as &$ids) {
            usort($ids, static fn (int $a, int $b): int => strcmp($code_by_id[$a], $code_by_id[$b]) ?: $a <=> $b);
        }
        unset($ids);

        $out   = [];
        $n     = 0;
        $stack = [];

        foreach (array_reverse($children[0] ?? []) as $root_id) {
            $stack[] = [$root_id, 0, false];
        }

        while ($stack !== []) {
            [$id, $depth_n, $leaving] = array_pop($stack);

            if ($leaving) {
                $out[$id]['rgt_n'] = ++$n;
                continue;
            }

            $out[$id] = ['lft_n' => ++$n, 'rgt_n' => 0, 'depth_n' => $depth_n];
            $stack[]  = [$id, $depth_n, true];

            foreach (array_reverse($children[$id] ?? []) as $child_id) {
                $stack[] = [$child_id, $depth_n + 1, false];
            }
        }

        if (count($out) !== count($parent_by_id)) {
            throw new TaxonomyException('A cycle: ' . (count($parent_by_id) - count($out)) . ' terms are not reachable from any root.');
        }

        return $out;
    }
}
