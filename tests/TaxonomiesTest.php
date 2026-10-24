<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Taxonomies — the library through Italix\Orm, on in-memory SQLite.
 *
 * Kinds, definitions, the interval index kept current, batches, deprecation, the delete refusal, SKOS mappings and
 * the collections kept apart by the ORM's partition.
 *
 * Run: php src/Libs/Italix/Taxonomies/tests/TaxonomiesTest.php
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

use Italix\Taxonomies\TaxonomyException;
use Italix\Taxonomies\Taxonomies;

use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Taxonomies - Taxonomies');

if (!extension_loaded('pdo_sqlite')) {
    echo "  SKIPPED - pdo_sqlite is absent.\n";
    exit(summary());
}

$throws = static function (callable $work): bool {
    try {
        $work();
    } catch (TaxonomyException $e) {
        return true;
    }

    return false;
};

$dm  = sqlite_memory();
$tax = new Taxonomies($dm);
$tax->install();
$tax->install();

// -----------------------------------------------------------------------------
section('kinds: declared before the first term');

test('install() runs twice without harm', true);
test('a term in an undeclared taxonomy is refused', $throws(fn () => $tax->define('shop:category:1', 'Uno')));

$tax->declare_taxonomy('shop', 'category', Taxonomies::KIND_TREE, 'Categories');
$tax->declare_taxonomy('shop', 'brand', Taxonomies::KIND_FLAT);
$tax->declare_taxonomy('shop', 'category', Taxonomies::KIND_TREE, 'Categories');
test('declare_taxonomy() is idempotent, kind_of() reads it back', $tax->kind_of('shop', 'category') === 'tree' && $tax->kind_of('shop', 'brand') === 'flat');
test('an unknown kind is refused', $throws(fn () => $tax->declare_taxonomy('shop', 'x', 'graph')));
test('a taxonomy name that is not kebab-case is refused', $throws(fn () => $tax->declare_taxonomy('shop', 'Bad_Name', 'flat')));
test('kind_of() an undeclared one is null', $tax->kind_of('shop', 'nope') === null);

// -----------------------------------------------------------------------------
section('define(): parents, cycles, the interval index');

//  surgery          instruments
//  ├─ forceps       └─ mirrors
//  │  └─ pedo
//  └─ sutures
$tax->define('shop:category:surgery', 'Surgery');
$tax->define('shop:category:forceps', 'Forceps', 'surgery');
$tax->define('shop:category:pedo', 'Paediatric forceps', 'forceps');
$tax->define('shop:category:sutures', 'Suture', 'surgery');
$tax->define('shop:category:instruments', 'Strumenti');
$tax->define('shop:category:mirrors', 'Specchietti', 'instruments');

test('descendants_of(): itself, then its subtree in walk order', $tax->descendants_of('shop:category:surgery') === ['surgery', 'forceps', 'pedo', 'sutures']);
test('ancestors_of(): root first, itself last', $tax->ancestors_of('shop:category:pedo') === ['surgery', 'forceps', 'pedo']);
test('is_under(): a grandchild yes, another root\'s child no', $tax->is_under('shop:category:pedo', 'shop:category:surgery')
    && !$tax->is_under('shop:category:mirrors', 'shop:category:surgery'));
test('an unknown term has no descendants and no path', $tax->descendants_of('shop:category:zz') === [] && $tax->ancestors_of('shop:category:zz') === []);
test('a parent must exist', $throws(fn () => $tax->define('shop:category:x', 'X', 'missing')));
test('a flat taxonomy takes no parent', $throws(fn () => $tax->define('shop:brand:zepf', 'Zepf', 'other')));
test('a cycle is refused', $throws(fn () => $tax->define('shop:category:surgery', 'Surgery', 'pedo')));
test('…and nothing moved', $tax->ancestors_of('shop:category:pedo') === ['surgery', 'forceps', 'pedo']);

$tax->define('shop:category:forceps', 'Forceps', 'instruments');
test('re-parenting moves the whole subtree, numbers and all', $tax->descendants_of('shop:category:instruments') === ['instruments', 'forceps', 'pedo', 'mirrors']
    && $tax->ancestors_of('shop:category:pedo') === ['instruments', 'forceps', 'pedo']);
$tax->define('shop:category:forceps', 'Forceps', 'surgery');

$t = $tax->find('shop:category:pedo');
test('find(): label, parent, depth, interval', $t !== null && $t['label'] === 'Paediatric forceps' && $t['parent_code'] === 'forceps' && $t['depth_n'] === 2
    && $t['rgt_n'] === $t['lft_n'] + 1);
test('terms(): walk order', array_column($tax->terms('shop', 'category'), 'code') === ['instruments', 'mirrors', 'surgery', 'forceps', 'pedo', 'sutures']);

$tax->define('shop:brand:zepf', 'Helmut Zepf');
$tax->define('shop:brand:larident', 'Larident');
test('a flat taxonomy is numbered too: containment means equality', $tax->descendants_of('shop:brand:zepf') === ['zepf']
    && !$tax->is_under('shop:brand:zepf', 'shop:brand:larident') && $tax->is_under('shop:brand:zepf', 'shop:brand:zepf'));

$tree = $tax->tree('shop', 'category');
test('tree(): the same answers in memory', $tree->descendants_of('forceps') === ['forceps', 'pedo'] && $tree->is_under('sutures', 'surgery'));

// -----------------------------------------------------------------------------
section('batch(): one renumber, at the end');

$inside = $tax->batch('shop', 'category', function (Taxonomies $tax): ?array {
    $tax->define('shop:category:dressings', 'Dressings', 'surgery');
    $tax->define('shop:category:autoclaves', 'Autoclaves', 'surgery');

    return $tax->find('shop:category:dressings');
});
test('inside a batch the new term is not numbered yet', $inside !== null && $inside['lft_n'] === 0);
test('after it, it is', $tax->descendants_of('shop:category:surgery') === ['surgery', 'autoclaves', 'dressings', 'forceps', 'pedo', 'sutures']);

$before = $tax->descendants_of('shop:category:surgery');
try {
    $tax->batch('shop', 'category', function (Taxonomies $tax): void {
        $tax->define('shop:category:lost', 'Persa', 'surgery');
        throw new \RuntimeException('stop');
    });
} catch (\RuntimeException $e) {
}
test('a batch that throws writes nothing', $tax->find('shop:category:lost') === null && $tax->descendants_of('shop:category:surgery') === $before);
test('inside a batch a cycle is refused at the define(), not at the end — the rest of the batch survives', (function () use ($tax, $throws): bool {
    $tax->batch('shop', 'category', function (Taxonomies $tax) use ($throws, &$refused): void {
        $refused = $throws(fn () => $tax->define('shop:category:surgery', 'Surgery', 'dressings'));
        $tax->define('shop:category:garze', 'Garze', 'surgery');
    });

    return $refused && $tax->ancestors_of('shop:category:garze') === ['surgery', 'garze'];
})());
test('…and the next define() renumbers at once again', (function () use ($tax): bool {
    $tax->define('shop:category:lampade', 'Lampade', 'instruments');

    return $tax->find('shop:category:lampade')['lft_n'] > 0;
})());

// -----------------------------------------------------------------------------
section('the same codes in another collection are another world');

$tax->declare_taxonomy('other-shop', 'category', Taxonomies::KIND_TREE);
$tax->define('other-shop:category:surgery', 'Surgery elsewhere');
test('same taxonomy name, same code, its own term', $tax->find('other-shop:category:surgery')['label'] === 'Surgery elsewhere'
    && $tax->find('shop:category:surgery')['label'] === 'Surgery');
test('its tree is its own', $tax->descendants_of('other-shop:category:surgery') === ['surgery']);
test('a parent is looked up in the term\'s own collection only', $throws(fn () => $tax->define('other-shop:category:x', 'X', 'forceps')));

// -----------------------------------------------------------------------------
section('deprecate(), delete()');

$tax->deprecate('shop:category:sutures', 'shop:category:forceps');
test('a deprecated term still resolves', $tax->find('shop:category:sutures')['deprecated'] === true);
test('…is not offered for new uses', !in_array('sutures', array_column($tax->terms('shop', 'category'), 'code'), true));
test('…unless asked', in_array('sutures', array_column($tax->terms('shop', 'category', true), 'code'), true));
test('…and still counts under its parent', $tax->is_under('shop:category:sutures', 'shop:category:surgery'));
test('a successor must be another live term of the same taxonomy', $throws(fn () => $tax->deprecate('shop:category:pedo', 'other-shop:category:surgery'))
    && $throws(fn () => $tax->deprecate('shop:category:pedo', 'shop:category:sutures')));

test('delete() refuses a term with children', $throws(fn () => $tax->delete('shop:category:forceps')));
$tax->delete('shop:category:pedo');
test('a leaf is (soft) deleted', $tax->find('shop:category:pedo') === null && $tax->descendants_of('shop:category:forceps') === ['forceps']);
test('…the row stays: defining it again brings it back', (function () use ($tax): bool {
    $tax->define('shop:category:pedo', 'Paediatric forceps', 'forceps');

    return $tax->descendants_of('shop:category:forceps') === ['forceps', 'pedo'];
})());

// -----------------------------------------------------------------------------
section('found by review (2026-09-25)');

$tax->declare_taxonomy('shop', 'sku', Taxonomies::KIND_FLAT);
foreach (['007', '7', '42', 'B', 'b'] as $code) {
    $tax->define("shop:sku:{$code}", $code);
}
test('codes are compared as bytes: 007 and 7, B and b are five terms', array_column($tax->terms('shop', 'sku'), 'code') === ['007', '42', '7', 'B', 'b']);
test('on MySQL the code column is VARBINARY — a _ci collation would merge B and b', str_contains(
    \Italix\Taxonomies\Schema::tables('mysql')[1]->to_create_sql(), '`code` VARBINARY(191) NOT NULL'));

$tax->define('shop:category:obsolete', 'Obsoleta', 'surgery');
$tax->define('shop:category:obsolete-child', 'Figlia', 'obsolete');
$tax->deprecate('shop:category:obsolete');
test('a new term under a deprecated parent is refused — not for new uses', $throws(fn () => $tax->define('shop:category:new-child', 'Nuova', 'obsolete')));
test('…a term already there is defined again unchanged — a nightly sync does exactly this', $tax->define('shop:category:obsolete-child', 'Figlia', 'obsolete') > 0);
test('…moving a term under it is refused', $throws(fn () => $tax->define('shop:category:mirrors', 'Specchietti', 'obsolete')));

$tax->define('shop:category:successor', 'Successore', 'surgery');
$tax->define('shop:category:predecessor', 'Predecessore', 'surgery');
$tax->deprecate('shop:category:predecessor', 'shop:category:successor');
test('related refuses a deprecated end on either side — it is symmetric', $throws(fn () => $tax->map('shop:category:predecessor', 'shop:category:successor', 'related'))
    && $throws(fn () => $tax->map('shop:category:successor', 'shop:category:predecessor', 'related')));
$tax->define('shop:category:heir', 'Erede', 'surgery');
$tax->define('shop:category:retired', 'Ritirata', 'surgery');
$tax->deprecate('shop:category:retired', 'shop:category:heir');
test('a term that replaces another cannot be deleted — the reference would dangle', $throws(fn () => $tax->delete('shop:category:heir')));

// Found by the re-review: a deleted predecessor still names its successor, and comes back with it.
$tax->define('shop:category:old-name', 'Vecchio', 'surgery');
$tax->define('shop:category:new-name', 'Nuovo', 'surgery');
$tax->deprecate('shop:category:old-name', 'shop:category:new-name');
$tax->delete('shop:category:old-name');
test('a successor named by a deleted term cannot be deleted either — the term can come back', $throws(fn () => $tax->delete('shop:category:new-name')));

$tax->define('shop:category:lapsed', 'Decaduta', 'obsolete-child');
$tax->delete('shop:category:lapsed');
$tax->deprecate('shop:category:obsolete-child');
test('a deleted term does not come back under a parent deprecated since', $throws(fn () => $tax->define('shop:category:lapsed', 'Decaduta', 'obsolete-child')));

$dm->execute("UPDATE ix_taxon_terms SET deprecated_dt = '2020-01-01 00:00:00' WHERE code = 'predecessor'");
$tax->deprecate('shop:category:predecessor');
$again = $dm->query("SELECT t.deprecated_dt, s.code AS successor FROM ix_taxon_terms t JOIN ix_taxon_terms s ON s.id = t.replaced_by_id WHERE t.code = 'predecessor'");
test('deprecating again keeps the first date and the successor', $again === [['deprecated_dt' => '2020-01-01 00:00:00', 'successor' => 'successor']]);

test('batch() refuses a scheme that is not kebab-case', $throws(fn () => $tax->batch('A B', '', fn () => null)));
$tax->declare_taxonomy('shop', 'category', Taxonomies::KIND_TREE);
test('declare_taxonomy() without a label keeps the one it has', $dm->query("SELECT label FROM ix_taxon_taxonomies WHERE taxonomy_c = 'category' AND collection_c = 'shop'")[0]['label'] === 'Categories');

// -----------------------------------------------------------------------------
section('map(): SKOS, owned by the mapper');

$tax->declare_taxonomy('universal', 'category', Taxonomies::KIND_TREE);
$tax->define('universal:category:dental-surgery', 'Dental surgery');
$tax->define('universal:category:old', 'Old');
$tax->deprecate('universal:category:old');

$tax->map('shop:category:surgery', 'universal:category:dental-surgery', 'exactMatch');
$tax->map('shop:category:surgery', 'universal:category:dental-surgery', 'exactMatch');
$tax->map('shop:category:mirrors', 'shop:category:lampade', 'related');
$tax->map('shop:category:lampade', 'shop:category:mirrors', 'related');

test('mappings_from(): what the term maps to', $tax->mappings_from('shop:category:surgery')
    === [['from' => 'shop:category:surgery', 'to' => 'universal:category:dental-surgery', 'relation_c' => 'exactMatch']]);
test('mappings_to(): who maps to it, from every collection', $tax->mappings_to('universal:category:dental-surgery')
    === [['from' => 'shop:category:surgery', 'to' => 'universal:category:dental-surgery', 'relation_c' => 'exactMatch']]);
test('map() is idempotent; related is symmetric — one link, read from either end', $tax->mappings_from('shop:category:lampade')
    === [['from' => 'shop:category:lampade', 'to' => 'shop:category:mirrors', 'relation_c' => 'related']]
    && $tax->mappings_from('shop:category:mirrors') === [['from' => 'shop:category:mirrors', 'to' => 'shop:category:lampade', 'relation_c' => 'related']]);
test('the mapping lives in the mapper\'s collection', $dm->query("SELECT collection_c FROM ix_taxon_mappings WHERE relation_c = 'exactMatch'")[0]['collection_c'] === 'shop');
test('a relation outside SKOS is refused', $throws(fn () => $tax->map('shop:category:surgery', 'universal:category:dental-surgery', 'sameAs')));
test('related across taxonomies is refused', $throws(fn () => $tax->map('shop:category:surgery', 'universal:category:dental-surgery', 'related')));
test('a …Match inside one taxonomy is refused', $throws(fn () => $tax->map('shop:category:surgery', 'shop:category:mirrors', 'closeMatch')));
test('a deprecated target is refused', $throws(fn () => $tax->map('shop:category:surgery', 'universal:category:old', 'broadMatch')));
test('an unknown end is refused', $throws(fn () => $tax->map('shop:category:surgery', 'universal:category:nope', 'broadMatch')));
test('a term with a mapping cannot be deleted', $throws(fn () => $tax->delete('universal:category:dental-surgery')));

// -----------------------------------------------------------------------------
section('containment(): a closure over qualified keys, one tree read per taxonomy');

$c_dm  = sqlite_memory();
$c_tax = new Taxonomies($c_dm);
$c_tax->install();
$c_tax->declare_taxonomy('shop', 'category', Taxonomies::KIND_TREE);
$c_tax->declare_taxonomy('shop', 'status', Taxonomies::KIND_FLAT);
$c_tax->define('shop:category:1', 'Tools');
$c_tax->define('shop:category:2', 'Rotary', '1');
$c_tax->define('shop:category:3', 'Burs', '2');
$c_tax->define('shop:category:4', 'Handpieces', '1');
$c_tax->define('shop:status:active', 'active');
$c_tax->define('shop:status:wip', 'wip');
$under = $c_tax->containment();

test('a grandchild is under the root, and a code under itself', $under('shop:category', '3', '1') && $under('shop:category', '3', '3'));
test('a parent is not under its child, nor a sibling under a sibling', !$under('shop:category', '1', '2') && !$under('shop:category', '4', '2'));
test('an unknown granted code contains nothing but itself', !$under('shop:category', '3', '99') && $under('shop:category', '99', '99'));
test('a flat taxonomy is equality', $under('shop:status', 'active', 'active') && !$under('shop:status', 'active', 'wip'));
test('a key that names no collection contains nothing else', !$under('category', '3', '1'));
test('each tree is read once, then answered from memory — what was added later is not seen', (static function () use ($c_tax, $under): bool {
    $c_tax->define('shop:category:6', 'New', '1');

    return !$under('shop:category', '6', '1') && $c_tax->containment()('shop:category', '6', '1');
})());

exit(summary());
