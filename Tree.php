<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Taxonomies;

/**
 * One taxonomy's intervals, in memory — for a caller that tests many pairs, like a permission check over every hit
 * of a search. Read once (`Taxonomies::tree()`), then every "is this under that" is O(1).
 *
 * A snapshot: numbers change on every renumber, so hold one for a request, never longer, and never store what it
 * returns.
 */
final class Tree
{
    /** @var list<array{0: int, 1: string}> [lft_n, code], by lft_n */
    private array $by_lft = [];

    /** @param array<string, array{lft_n: int, rgt_n: int, depth_n: int}> $interval_by_code */
    public function __construct(private readonly array $interval_by_code)
    {
        foreach ($interval_by_code as $code => $interval) {
            $this->by_lft[] = [$interval['lft_n'], (string) $code];
        }

        usort($this->by_lft, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    }

    public function has(string $code): bool
    {
        return isset($this->interval_by_code[$code]);
    }

    /** @return array{lft_n: int, rgt_n: int, depth_n: int}|null */
    public function interval_of(string $code): ?array
    {
        return $this->interval_by_code[$code] ?? null;
    }

    /** Whether `$code` is `$ancestor_code` or lies under it. An unknown code is under nothing. */
    public function is_under(string $code, string $ancestor_code): bool
    {
        $a = $this->interval_by_code[$ancestor_code] ?? null;
        $b = $this->interval_by_code[$code] ?? null;

        return $a !== null && $b !== null && $a['lft_n'] <= $b['lft_n'] && $b['rgt_n'] <= $a['rgt_n'];
    }

    /**
     * The code and everything under it, in walk order; [] for an unknown code.
     *
     * @return list<string>
     */
    public function descendants_of(string $code): array
    {
        $a = $this->interval_by_code[$code] ?? null;

        if ($a === null) {
            return [];
        }

        $lo = 0;
        $hi = count($this->by_lft);

        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if ($this->by_lft[$mid][0] < $a['lft_n']) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        $out = [];

        for ($i = $lo; $i < count($this->by_lft) && $this->by_lft[$i][0] <= $a['rgt_n']; $i++) {
            $out[] = $this->by_lft[$i][1];
        }

        return $out;
    }

    /**
     * The path to the code, root first, the code itself last; [] for an unknown code.
     *
     * @return list<string>
     */
    public function ancestors_of(string $code): array
    {
        $b = $this->interval_by_code[$code] ?? null;

        if ($b === null) {
            return [];
        }

        $out = [];

        foreach ($this->by_lft as [$lft_n, $other]) {
            if ($lft_n > $b['lft_n']) {
                break;
            }

            if ($this->interval_by_code[$other]['rgt_n'] >= $b['rgt_n']) {
                $out[] = $other;
            }
        }

        return $out;
    }
}
