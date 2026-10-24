# Italix Taxonomies

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

Several vocabularies side by side — category trees and flat tag lists — each in its own collection, with SKOS labels
in every language, explicit SKOS mappings between them, and a pre/post-order interval index that answers "is this
under that" in O(1).

Built on `Italix\Orm`'s partitions (`Table::partitioned_by()`, 2.32.0): a collection is a partition, so the same
taxonomy name and the same codes in two collections are two separate worlds.

```bash
php src/Libs/Italix/Taxonomies/tests/TreeTest.php         # pure: TermRef, Numbering, Tree
php src/Libs/Italix/Taxonomies/tests/TaxonomiesTest.php   # through the ORM, on in-memory SQLite
php src/Libs/Italix/Taxonomies/tests/LabelsTest.php       # SKOS labels, the same way
```

---

## A term is named in full

`collection:taxonomy:code` — `shop:category:42`. The collection is never implied: the `''` collection
is written `:status:active`, and `status:active` is an error. Collection and taxonomy are kebab-case; the code is the
last part and may hold a `:`.

```php
$tax = new Taxonomies($dm);
$tax->install();                                              // four ix_taxon_* tables; safe to run again

$tax->declare_taxonomy('shop', 'category', Taxonomies::KIND_TREE, 'Categories');
$tax->declare_taxonomy('shop', 'brand', Taxonomies::KIND_FLAT);

$tax->define('shop:category:6127', 'Kitchen');
$tax->define('shop:category:6130', 'Knives', '6127');      // parent: same collection and taxonomy
```

`define()` is declarative: it sets the label **and** the parent, so a null parent makes the term a root — pass the
parent again to keep it. Codes compare byte for byte on every database: `007` and `7`, `B` and `b` are different
codes (on MySQL the column is `VARBINARY`, since a `_ci` collation would merge them).

## Two kinds

- **`tree`** — one parent at most. No second parent: a term that belongs under two branches keeps one, and the other
  link is a `related` mapping.
- **`flat`** — tags. A parent is refused.

A taxonomy is declared before its first term; its kind changes only while it has none.

## The interval index

`parent_id` is the truth. Every term also carries `lft_n`, `rgt_n`, `depth_n` — the numbers a depth-first walk enters
and leaves it at — so a term's subtree is exactly the terms whose `lft_n` lies inside its `[lft_n, rgt_n]`:

```php
$tax->descendants_of('shop:category:6127');   // ['6127', '6130', …] — one index range
$tax->ancestors_of('shop:category:6130');     // ['6127', '6130'] — root first
$tax->is_under($card_term, $granted_term);                // containment

$tree = $tax->tree('shop', 'category');       // in memory, for many tests in one request
$tree->is_under('6130', '6127');

$under = $tax->containment();                             // a closure over qualified keys, one tree read per taxonomy
$under('shop:category', '6130', '6127');      // what an access check takes (Italix\Acl\Dnf::satisfies())
```

- The whole taxonomy is **renumbered in the transaction that changed it**. A flat taxonomy is numbered too, every term
  a root, so containment means equality there — one rule for both kinds.
- `batch($collection_c, $taxonomy_c, fn)` runs many changes as one transaction and renumbers **once**, at the end —
  what a sync of a whole tree uses. Inside it, the numbers are those of before the batch.
- Siblings are walked by code in byte order: the same tree always gets the same numbers.
- **Never store `lft_n`/`rgt_n` outside this library** — grants, cards and caches hold codes. The numbers change on
  every renumber, and a stored one goes silently wrong: nested sets' classic trap.

## Deprecate, don't delete

```php
$tax->deprecate('shop:category:old', 'shop:category:new');   // still resolves; not offered anew
$tax->delete('shop:category:typo');     // soft; refused with children, any mapping, or as anyone's successor
```

This library cannot know whether a card or a grant elsewhere uses a term, so the rule for callers is to deprecate.
A deprecated term takes no new children (a child already there stays, so a sync can define it again; a deleted one
does not come back under it), no `related` link, and no mapping to it. Deprecating again keeps the first date; a
successor given replaces the old one. A deleted term comes back, as it was — successor included — if it is defined
again, which is why a successor stays undeletable while even a deleted term names it.

## Mappings (SKOS)

```php
$tax->map('shop7:category:42', 'universal:category:kitchen-knives', 'exactMatch');
$tax->map('shop:category:pans', 'shop:category:lids', 'related');

$tax->mappings_from('shop7:category:42');              // what it maps to
$tax->mappings_to('universal:category:kitchen-knives');   // who maps to it, from every collection
```

- `exactMatch`, `closeMatch`, `broadMatch`, `narrowMatch`, `relatedMatch` link **different** taxonomies; `related`
  links two terms of the **same** one, and is symmetric.
- A mapping is written in the collection of its `from` term: whoever maps owns it, and exporting that collection takes
  its mappings along.
- **Permissions never follow a mapping on their own.** Following one is a query's explicit choice.
- A deprecated target is refused — and for `related`, a deprecated end on either side.

## Labels in every language (SKOS)

A term's own `label` is one string in no particular language — a fallback, and what an admin screen shows. The names
people read are SKOS labels, per language, in `ix_taxon_labels`:

| kind code | SKOS | schema.org | what it is |
|---|---|---|---|
| `pref` | `skos:prefLabel` | `name` | the name shown — one per language at most |
| `alt` | `skos:altLabel` | `alternateName` | a synonym: found by a search, shown as "also called" |
| `hidden` | `skos:hiddenLabel` | — never output | found, never shown: an old name, a legacy slug, a misspelling |

The kinds are stored as these snake_case codes (`Taxonomies::LABEL_PREF`, `LABEL_ALT`, `LABEL_HIDDEN`); the camelCase
names belong to the output, mapped where JSON-LD or RDF is written.

```php
$tax->set_labels('media:category:book.mystery', [            // declarative: every language, every label
    'it' => ['pref' => 'Giallo', 'alt' => ['Noir', 'Poliziesco'], 'hidden' => ['poliziesco']],
    'en' => ['pref' => 'Mystery', 'alt' => ['Crime fiction']],
    'de' => ['pref' => 'Krimi'],
]);

$tax->labels_of('media:category:book.mystery');               // ['de' => ['pref' => 'Krimi', 'alt' => [], 'hidden' => []], …]
$tax->labels('media', 'category');                            // every term's, by code, in one read
$tax->pref_label('media:category:book.mystery', Locale::chain('de-CH', 'it'));   // 'Krimi' — the chain falls through
$tax->find_by_label('media', 'category', 'noir');             // [['code' => 'book.mystery', 'lang_c' => 'it', 'kind_c' => 'alt', …]]
$tax->find_by_label('media', 'category', 'poliziesco', 'it', [Taxonomies::LABEL_HIDDEN]);   // a legacy name only
```

- **Declarative**, like `define()`: a language or kind left out loses its labels, `[]` removes them all; rows that did
  not change are not rewritten. Alt and hidden labels keep the order given.
- **SKOS's rules inside one term and language:** one `pref`, and `pref`, `alt` and `hidden` disjoint. A label listed
  twice is refused as well.
- **Not enforced across terms:** two terms may share a label. "Mystery" under books and under films is normal; telling
  them apart is the parent's job.
- **Languages** are BCP 47 tags as sites use them: `it`, `de-CH`, `zh-Hant-TW`, `es-419`. `pref_label()` takes the
  fallback chain from its caller (`Italix\I18n\Locale::chain()` builds one), so this library does not depend on I18n;
  with no language of the chain it gives the term's own label.
- **Matching** (`find_by_label()`, the disjointness rule) compares `Taxonomies::match_form()`: NFC, lower case,
  white space collapsed. Accents are kept — "Città" is not "Citta" — and `match_c` is compared byte for byte, on MySQL
  too, like a code. Folding accents is a search engine's decision, not a vocabulary's.
- Deprecated terms keep and take labels (a reference held elsewhere must still be named) and are flagged when found;
  deleted terms are not found, and come back with their labels when defined again.
