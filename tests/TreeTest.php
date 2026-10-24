<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Taxonomies — TermRef, Numbering, Tree: the pure parts.
 *
 * The interval index is checked against the thing it replaces — walking parent pointers — on random forests, not
 * only on a hand-drawn tree: every subtree contiguous, every "is under" and every path equal to the walk's.
 *
 * Run: php src/Libs/Italix/Taxonomies/tests/TreeTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Taxonomies\Numbering;
use Italix\Taxonomies\TaxonomyException;
use Italix\Taxonomies\TermRef;
use Italix\Taxonomies\Tree;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Taxonomies - TermRef, Numbering, Tree');

$throws = static function (callable $work): bool {
    try {
        $work();
    } catch (TaxonomyException $e) {
        return true;
    }

    return false;
};

// -----------------------------------------------------------------------------
section('TermRef — the collection is never implied');

$ref = TermRef::parse('shop:category:42');
test('collection, taxonomy, code', [$ref->collection_c, $ref->taxonomy_c, $ref->code] === ['shop', 'category', '42']);
test('scheme() is the key a grant names', $ref->scheme() === 'shop:category');
test('it prints back as written', (string) $ref === 'shop:category:42');
test('the \'\' collection is written :status:active', TermRef::parse(':status:active')->collection_c === '');
test('status:active — no collection part — is refused, not defaulted', $throws(fn () => TermRef::parse('status:active')));
test('a code may hold a colon: it is the last part', TermRef::parse('nsm-plus:axis:a:b')->code === 'a:b');
test('a collection that is not kebab-case is refused', $throws(fn () => TermRef::parse('Shop_Catalogue:category:1')));
test('an empty taxonomy is refused', $throws(fn () => TermRef::parse('shop::1')));
test('an empty code is refused', $throws(fn () => TermRef::parse('shop:category:')));
test('in_scheme_of() compares collection and taxonomy', TermRef::parse('a:t:1')->in_scheme_of(TermRef::parse('a:t:2'))
    && !TermRef::parse('a:t:1')->in_scheme_of(TermRef::parse('b:t:1')));

// -----------------------------------------------------------------------------
section('Numbering — a hand-drawn tree');

//  1 root-a          5 root-b
//  ├─ 2 a1           └─ 6 b1
//  │  └─ 4 a1x
//  └─ 3 a2
$parent = [1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => null, 6 => 5];
$code   = [1 => 'root-a', 2 => 'a1', 3 => 'a2', 4 => 'a1x', 5 => 'root-b', 6 => 'b1'];
$n      = Numbering::number($parent, $code);

test('the root spans its whole subtree', $n[1] === ['lft_n' => 1, 'rgt_n' => 8, 'depth_n' => 0]);
test('a child sits inside its parent', $n[4]['lft_n'] > $n[2]['lft_n'] && $n[4]['rgt_n'] < $n[2]['rgt_n'] && $n[4]['depth_n'] === 2);
test('a leaf is lft + 1 = rgt', $n[3]['rgt_n'] === $n[3]['lft_n'] + 1);
test('the second root follows the first', $n[5]['lft_n'] === 9 && $n[6]['depth_n'] === 1);
test('siblings are walked by code, not by id', Numbering::number([1 => null, 2 => 1, 3 => 1], [1 => 'r', 2 => 'z', 3 => 'b'])[3]['lft_n'] === 2);
test('a parent outside the set makes a root', Numbering::number([7 => 99], [7 => 'x'])[7] === ['lft_n' => 1, 'rgt_n' => 2, 'depth_n' => 0]);
$flat = Numbering::number([1 => null, 2 => null], [1 => 'b', 2 => 'a']);
test('a flat list: every term a root, one interval each, by code', $flat[2] === ['lft_n' => 1, 'rgt_n' => 2, 'depth_n' => 0] && $flat[1]['lft_n'] === 3);
test('a cycle is refused', $throws(fn () => Numbering::number([1 => 2, 2 => 1], [1 => 'a', 2 => 'b'])));

// Found by review: PHP's <=> compares numeric strings as numbers and the rest as text — not transitive, so the walk
// order of '10', '1a', '9' depended on the order they came in.
$orders = [];
foreach ([[1, 2, 3], [2, 3, 1], [3, 1, 2], [3, 2, 1]] as $perm) {
    $codes    = [1 => '10', 2 => '1a', 3 => '9'];
    $permuted = [];
    foreach ($perm as $id) {
        $permuted[$id] = null;
    }
    $numbers = Numbering::number($permuted, $codes);
    uasort($numbers, static fn (array $a, array $b): int => $a['lft_n'] <=> $b['lft_n']);
    $orders[] = implode(',', array_map(static fn (int $id): string => $codes[$id], array_keys($numbers)));
}
test('siblings in byte order, whatever the input order — 10, 1a, 9', array_unique($orders) === ['10,1a,9']);

// -----------------------------------------------------------------------------
section('Tree — the snapshot');

/** Numbering's result (id => interval, in walk order) keyed by code, as Taxonomies::tree() builds it. */
$by_code = static fn (array $numbers, array $code): array => array_combine(array_map(static fn (int $id): string => $code[$id], array_keys($numbers)), array_values($numbers));

$tree = new Tree($by_code($n, $code));
test('is_under: itself', $tree->is_under('a1', 'a1'));
test('is_under: a grandchild', $tree->is_under('a1x', 'root-a'));
test('is_under: not a sibling', !$tree->is_under('a2', 'a1'));
test('is_under: not across roots', !$tree->is_under('b1', 'root-a'));
test('is_under: an unknown code is under nothing, and nothing is under it', !$tree->is_under('zz', 'root-a') && !$tree->is_under('a1', 'zz'));
test('descendants_of: itself first, then its subtree in walk order', $tree->descendants_of('root-a') === ['root-a', 'a1', 'a1x', 'a2']);
test('descendants_of: a leaf is itself', $tree->descendants_of('a2') === ['a2']);
test('descendants_of: unknown is []', $tree->descendants_of('zz') === []);
test('ancestors_of: root first, itself last', $tree->ancestors_of('a1x') === ['root-a', 'a1', 'a1x']);
test('ancestors_of: unknown is []', $tree->ancestors_of('zz') === []);

// -----------------------------------------------------------------------------
section('random forests — intervals against the parent walk');

mt_srand(20260925);
$failures = [];

for ($round = 0; $round < 40; $round++) {
    $size   = mt_rand(1, 120);
    $parent = [];
    $code   = [];
    $ids    = range(1, $size);
    shuffle($ids);

    foreach ($ids as $i => $id) {
        $parent[$id] = $i === 0 || mt_rand(0, 9) === 0 ? null : $ids[mt_rand(0, $i - 1)];
        $code[$id]   = 'c' . $id;
    }

    $numbers = Numbering::number($parent, $code);
    $tree    = new Tree($by_code($numbers, $code));

    $walk = static function (int $id) use ($parent): array {
        $path = [];
        for ($cur = $id; $cur !== null; $cur = $parent[$cur]) {
            array_unshift($path, $cur);
        }

        return $path;
    };

    $used = [];
    foreach ($numbers as $id => $iv) {
        $used[] = $iv['lft_n'];
        $used[] = $iv['rgt_n'];

        if ($iv['depth_n'] !== count($walk($id)) - 1) {
            $failures[] = "depth of {$id}";
        }
    }
    sort($used);
    if ($used !== range(1, 2 * $size)) {
        $failures[] = "round {$round}: numbers are not 1..2n";
    }

    foreach (array_keys($parent) as $a) {
        $expected_desc = [];
        foreach (array_keys($parent) as $b) {
            $under = in_array($a, $walk($b), true);
            if ($under !== $tree->is_under($code[$b], $code[$a])) {
                $failures[] = "is_under({$b}, {$a})";
            }
            if ($under) {
                $expected_desc[] = $code[$b];
            }
        }

        $got = $tree->descendants_of($code[$a]);
        sort($got);
        sort($expected_desc);
        if ($got !== $expected_desc) {
            $failures[] = "descendants_of({$a})";
        }

        if ($tree->ancestors_of($code[$a]) !== array_map(static fn (int $id): string => $code[$id], $walk($a))) {
            $failures[] = "ancestors_of({$a})";
        }
    }

    $reversed = array_reverse($parent, true);
    if (Numbering::number($reversed, $code) != $numbers) {
        $failures[] = "round {$round}: the numbers depend on insertion order";
    }
}

test('40 random forests: depth, 1..2n, is_under, descendants, ancestors all agree with the walk, whatever the order',
    $failures === []);

if ($failures !== []) {
    print_r(array_slice($failures, 0, 5));
}

exit(summary());
