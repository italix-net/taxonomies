<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Taxonomies — SKOS labels in every language, on in-memory SQLite.
 *
 * set_labels() declarative and idempotent, the SKOS rules it enforces (one pref per language, the three kinds
 * disjoint), the reads (labels_of, labels, pref_label with a fallback chain, find_by_label) and how labels follow a
 * term through deprecation, deletion and the collections kept apart.
 *
 * Run: php src/Libs/Italix/Taxonomies/tests/LabelsTest.php
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

use Italix\Taxonomies\Schema;
use Italix\Taxonomies\TaxonomyException;
use Italix\Taxonomies\Taxonomies;

use function Italix\Orm\sqlite_memory;
use function Italix\Testing\{suite, section, test, summary};

suite('Italix Taxonomies - labels');

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

$tax->declare_taxonomy('media', 'category', Taxonomies::KIND_TREE);
$tax->declare_taxonomy('media', 'tag', Taxonomies::KIND_FLAT);
$tax->define('media:category:book', 'book');
$tax->define('media:category:book.mystery', 'mystery', 'book');
$tax->define('media:category:movie', 'movie');
$tax->define('media:category:movie.mystery', 'mystery', 'movie');

$label_rows = static fn (): int => count($dm->select()->from($tax->tables()[3])->across_all()->execute());

// -----------------------------------------------------------------------------
section('set_labels() and labels_of()');

$tax->set_labels('media:category:book.mystery', [
    'it' => ['pref' => 'Giallo', 'alt' => ['Poliziesco', 'Noir'], 'hidden' => ['poliziesco-classico']],
    'en' => ['pref' => 'Mystery', 'alt' => ['Crime fiction']],
    'de' => ['pref' => 'Krimi'],
]);

test('the fourth table is ix_taxon_labels', $tax->tables()[3]->get_name() === Schema::LABELS);
test('labels_of() reads them back, languages in byte order, every kind present', $tax->labels_of('media:category:book.mystery') === [
    'de' => ['pref' => 'Krimi', 'alt' => [], 'hidden' => []],
    'en' => ['pref' => 'Mystery', 'alt' => ['Crime fiction'], 'hidden' => []],
    'it' => ['pref' => 'Giallo', 'alt' => ['Poliziesco', 'Noir'], 'hidden' => ['poliziesco-classico']],
]);
test('alt labels keep the order given, not alphabetical', $tax->labels_of('media:category:book.mystery')['it']['alt'] === ['Poliziesco', 'Noir']);
test('a term without labels has none', $tax->labels_of('media:category:movie.mystery') === []);
test('an unknown term: []', $tax->labels_of('media:category:nope') === []);
test('a label is trimmed', (function () use ($tax): bool {
    $tax->set_labels('media:category:movie', ['it' => ['pref' => '  Film ']]);

    return $tax->labels_of('media:category:movie')['it']['pref'] === 'Film';
})());

// -----------------------------------------------------------------------------
section('declarative: what is left out goes, what is unchanged stays');

$before   = $dm->select()->from($tax->tables()[3])->across_all()->execute();
$tax->set_labels('media:category:book.mystery', [
    'it' => ['pref' => 'Giallo', 'alt' => ['Poliziesco', 'Noir'], 'hidden' => ['poliziesco-classico']],
    'en' => ['pref' => 'Mystery', 'alt' => ['Crime fiction']],
    'de' => ['pref' => 'Krimi'],
]);
test('the same labels again rewrite no row: same ids', array_column($dm->select()->from($tax->tables()[3])->across_all()->execute(), 'id') === array_column($before, 'id'));

$tax->set_labels('media:category:book.mystery', [
    'it' => ['pref' => 'Giallo', 'alt' => ['Noir', 'Poliziesco']],
    'en' => ['pref' => 'Mystery'],
]);
test('a language left out loses its labels', !isset($tax->labels_of('media:category:book.mystery')['de']));
test('a kind left out loses its labels', $tax->labels_of('media:category:book.mystery')['it']['hidden'] === []
    && $tax->labels_of('media:category:book.mystery')['en']['alt'] === []);
test('a new order is kept', $tax->labels_of('media:category:book.mystery')['it']['alt'] === ['Noir', 'Poliziesco']);

$tax->set_labels('media:category:movie', []);
test('[] removes every label of the term', $tax->labels_of('media:category:movie') === []);
test('...and only of that term', $label_rows() === 4);

// -----------------------------------------------------------------------------
section('refused, and nothing written');

$snapshot = $tax->labels_of('media:category:book.mystery');
$keep     = ['pref' => 'Giallo'];

test('an unknown term', $throws(fn () => $tax->set_labels('media:category:nope', ['it' => $keep])));
test('a language that is no BCP 47 tag: ITA', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['ITA' => $keep])));
test('...nor it_IT', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it_IT' => $keep])));
test('an unknown kind', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['name' => 'Giallo']])));
test('the camelCase SKOS name is not a kind here', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['prefLabel' => 'Giallo']])));
test('two pref labels in one language', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['pref' => ['Giallo', 'Thriller']]])));
test('alt as a bare string, not a list', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['alt' => 'Noir']])));
test('an empty label', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['pref' => '  ']])));
test('a label longer than 255', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['pref' => str_repeat('g', 256)]])));
test('pref and alt the same (SKOS: disjoint)', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['pref' => 'Giallo', 'alt' => ['giallo']]])));
test('alt and hidden the same', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['alt' => ['Noir'], 'hidden' => ['NOIR']]])));
test('one alt twice, spacing aside', $throws(fn () => $tax->set_labels('media:category:book.mystery', ['it' => ['alt' => ['Crime  fiction', 'crime fiction']]])));
test('a refused call changed nothing', $tax->labels_of('media:category:book.mystery') === $snapshot);
test('the same word in two languages is fine', (function () use ($tax): bool {
    $tax->set_labels('media:category:book', ['it' => ['pref' => 'Libri'], 'en' => ['pref' => 'Books'], 'de' => ['pref' => 'Bücher'], 'fr' => ['pref' => 'Livres'], 'es' => ['pref' => 'Libros'], 'pt' => ['pref' => 'Livros'], 'la' => ['pref' => 'Libri']]);

    return $tax->labels_of('media:category:book')['la']['pref'] === 'Libri';
})());
test('two terms may share a label (Mystery under books and films)', (function () use ($tax): bool {
    $tax->set_labels('media:category:movie.mystery', ['it' => ['pref' => 'Giallo'], 'en' => ['pref' => 'Mystery']]);

    return $tax->labels_of('media:category:movie.mystery')['it']['pref'] === 'Giallo';
})());
test('region, script and UN M49 tags are accepted', (function () use ($tax): bool {
    $tax->set_labels('media:category:movie', ['de-CH' => ['pref' => 'Filme'], 'zh-Hant-TW' => ['pref' => '電影'], 'es-419' => ['pref' => 'Películas']]);

    return array_keys($tax->labels_of('media:category:movie')) === ['de-CH', 'es-419', 'zh-Hant-TW'];
})());

// -----------------------------------------------------------------------------
section('pref_label(): a fallback chain, then the term\'s own label');

test('the first language of the chain', $tax->pref_label('media:category:book.mystery', ['en', 'it']) === 'Mystery');
test('a language without labels falls through', $tax->pref_label('media:category:book.mystery', ['de-CH', 'de', 'it']) === 'Giallo');
test('a regional tag of its own wins over its language', $tax->pref_label('media:category:movie', ['de-CH', 'de']) === 'Filme');
test('no language of the chain: the term\'s own label', $tax->pref_label('media:category:book.mystery', ['fr']) === 'mystery');
test('an empty chain: the term\'s own label', $tax->pref_label('media:category:book.mystery', []) === 'mystery');
test('an unknown term: null', $tax->pref_label('media:category:nope', ['it']) === null);

// -----------------------------------------------------------------------------
section('labels(): a whole taxonomy in one read');

$all = $tax->labels('media', 'category');
test('by code, in byte order, terms without labels left out', array_keys($all) === ['book', 'book.mystery', 'movie', 'movie.mystery']);
test('each as labels_of() gives it', $all['book.mystery'] === $tax->labels_of('media:category:book.mystery'));
test('a taxonomy without terms: []', $tax->labels('media', 'tag') === []);

// -----------------------------------------------------------------------------
section('find_by_label()');

$tax->set_labels('media:category:book.mystery', [
    'it' => ['pref' => 'Giallo', 'alt' => ['Noir', 'Poliziesco'], 'hidden' => ['poliziesco-classico']],
    'en' => ['pref' => 'Mystery'],
]);

$codes = static fn (array $hits): array => array_map(static fn (array $h): string => "{$h['code']} {$h['lang_c']} {$h['kind_c']}", $hits);

test('case and spacing aside', $codes($tax->find_by_label('media', 'category', '  NOIR ')) === ['book.mystery it alt']);
test('a shared label finds both terms, by code', $codes($tax->find_by_label('media', 'category', 'giallo')) === ['book.mystery it pref', 'movie.mystery it pref']);
test('in one language only', $tax->find_by_label('media', 'category', 'Giallo', 'en') === []);
test('of the kinds asked: a hidden label is not a search hit', $tax->find_by_label('media', 'category', 'poliziesco-classico', null, [Taxonomies::LABEL_PREF, Taxonomies::LABEL_ALT]) === []);
test('...but resolves when hidden is asked', $codes($tax->find_by_label('media', 'category', 'poliziesco-classico', 'it', [Taxonomies::LABEL_HIDDEN])) === ['book.mystery it hidden']);
test('a hit says what matched', $tax->find_by_label('media', 'category', 'noir')[0] === ['code' => 'book.mystery', 'lang_c' => 'it', 'kind_c' => 'alt', 'label' => 'Noir', 'deprecated' => false]);
test('accents are not folded: Citta is not Città', (function () use ($tax): bool {
    $tax->define('media:tag:citta', 'città');
    $tax->set_labels('media:tag:citta', ['it' => ['pref' => 'Città']]);

    return count($tax->find_by_label('media', 'tag', 'CITTÀ')) === 1 && $tax->find_by_label('media', 'tag', 'Citta') === [];
})());
test('only in the taxonomy asked', $tax->find_by_label('media', 'tag', 'Giallo') === []);
test('empty text, or no kinds: []', $tax->find_by_label('media', 'category', '  ') === [] && $tax->find_by_label('media', 'category', 'Giallo', null, []) === []);

// -----------------------------------------------------------------------------
section('labels through deprecation, deletion and collections');

$tax->define('media:tag:snow', 'snow');
$tax->define('media:tag:neve', 'neve');
$tax->set_labels('media:tag:snow', ['en' => ['pref' => 'Snow']]);
$tax->deprecate('media:tag:snow', 'media:tag:neve');
test('a deprecated term keeps its labels, and is flagged when found', $tax->find_by_label('media', 'tag', 'snow')[0]['deprecated'] === true);
test('a deprecated term takes new labels: it must still be named', (function () use ($tax): bool {
    $tax->set_labels('media:tag:snow', ['en' => ['pref' => 'Snow'], 'it' => ['hidden' => ['neve-vecchia']]]);

    return $tax->labels_of('media:tag:snow')['it']['hidden'] === ['neve-vecchia'];
})());

$tax->define('media:tag:typo', 'typo');
$tax->set_labels('media:tag:typo', ['it' => ['pref' => 'Refuso']]);
$tax->delete('media:tag:typo');
test('a deleted term is not found by its labels', $tax->find_by_label('media', 'tag', 'Refuso') === []);
test('...nor listed by labels()', !isset($tax->labels('media', 'tag')['typo']));
test('...nor takes labels', $throws(fn () => $tax->set_labels('media:tag:typo', ['it' => ['pref' => 'Refuso']])));
$tax->define('media:tag:typo', 'typo');
test('defined again, it comes back with its labels', $tax->labels_of('media:tag:typo') === ['it' => ['pref' => 'Refuso', 'alt' => [], 'hidden' => []]]);

$tax->declare_taxonomy('other', 'category', Taxonomies::KIND_TREE);
$tax->define('other:category:book.mystery', 'mystery');
test('the same code in another collection has its own labels', $tax->labels_of('other:category:book.mystery') === []);
$tax->set_labels('other:category:book.mystery', ['it' => ['pref' => 'Giallo']]);
test('...and is found only there', $codes($tax->find_by_label('other', 'category', 'giallo')) === ['book.mystery it pref']
    && count($tax->find_by_label('media', 'category', 'giallo')) === 2);

// -----------------------------------------------------------------------------
section('match_form()');

test('lower case, one space, trimmed', Taxonomies::match_form("  Crime \t  Fiction\n") === 'crime fiction');
test('multibyte lower case: BÜCHER', Taxonomies::match_form('BÜCHER') === 'bücher');
test('NFC: a decomposed ü matches the composed one', !class_exists(\Normalizer::class) || Taxonomies::match_form("Bu\u{0308}cher") === Taxonomies::match_form('Bücher'));

exit(summary());
