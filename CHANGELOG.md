# Changelog — italix/taxonomies

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [0.3.0] — 2026-10-24

First public release; 0.1.0 and 0.2.0 below were never published on their own.

### Added

- **SKOS labels in every language**: a fourth table, `ix_taxon_labels` (`install()` adds it to an existing install),
  and `set_labels()` (declarative, per term, every language), `labels_of()`, `labels()` (a whole taxonomy in one read),
  `pref_label()` (a fallback chain, then the term's own label) and `find_by_label()` (by language and kind: `hidden`
  alone for a legacy name, `pref` and `alt` for a search). Kinds are stored as codes, `pref`, `alt` and `hidden`
  (`LABEL_PREF`, `LABEL_ALT`, `LABEL_HIDDEN`); the camelCase SKOS and schema.org names belong to the output.
- `match_form()`: how labels compare — NFC, lower case, white space collapsed, accents kept — stored as `match_c`
  and compared byte for byte, VARBINARY on MySQL like `code`.
- `LabelsTest` (57); four rule mutations each caught; the lookups also checked on MySQL (accents not folded by a
  `_ci` collation, `ß` not `ss`).

### Changed

- `tables()` returns four tables; `Schema::tables()` too. Destructuring the first three still works.
- Requires `ext-mbstring`.

## [0.2.0] — unreleased

### Added

- `Taxonomies::containment()` — "is this code that one, or under it?" as a closure over a qualified taxonomy key, one
  `tree()` read per taxonomy for the closure's life: what an access check takes (`Italix\Acl\Dnf::satisfies()`).
  Moved here from an application's code: it reads trees, so it is this library's.

## [0.1.0] — unreleased

### Added

- First version:
  `Taxonomies` (declare, define, batch, deprecate, delete, map, and the reads), `TermRef`, `Numbering`, `Tree`,
  `Schema` (`ix_taxon_taxonomies`, `ix_taxon_terms`, `ix_taxon_mappings`, each `partitioned_by('collection_c')`).
  `TreeTest` 30 assertions, `TaxonomiesTest` 57 — 13 of them added after two rounds of adversarial review (sibling
  order not total under PHP's `<=>`; codes merged by MySQL's `_ci` collation, or by SQLite's NUMERIC affinity had the
  column been VARBINARY there; a successor deletable, also once its predecessor was deleted; `related` from a
  deprecated term; children put or restored under a deprecated parent; deprecating again resetting the date).
