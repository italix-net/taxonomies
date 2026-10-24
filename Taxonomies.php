<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Taxonomies;

use Italix\Orm\DataManager;
use Italix\Orm\Schema\Column;
use Italix\Orm\Schema\Table;

use function Italix\Orm\Operators\{and_, eq, gte, in_, lte, or_};

/**
 * Several vocabularies, each in its own collection: trees (categories) and flat lists (tags), their terms, their
 * SKOS labels in every language, and the explicit SKOS mappings between them.
 *
 * Every term carries pre/post-order numbers (`lft_n`, `rgt_n`, `depth_n`) — an index over `parent_id`, which stays
 * the truth, renumbered whole in the transaction that changed the taxonomy, once per `batch()`. A flat taxonomy is
 * numbered too, every term a root, so "is this under that" is the same containment test for both kinds and means
 * equality for a flat one.
 */
final class Taxonomies
{
    public const KIND_TREE = 'tree';
    public const KIND_FLAT = 'flat';

    /** The SKOS mapping relations across schemes, and `related` inside one. */
    public const RELATIONS = ['exactMatch', 'closeMatch', 'broadMatch', 'narrowMatch', 'relatedMatch', 'related'];

    /**
     * The SKOS label kinds, stored as these codes: `pref` (the name shown — skos:prefLabel, schema.org `name`), `alt`
     * (a synonym — skos:altLabel, schema.org `alternateName`), `hidden` (found, never shown — skos:hiddenLabel).
     */
    public const LABEL_PREF   = 'pref';
    public const LABEL_ALT    = 'alt';
    public const LABEL_HIDDEN = 'hidden';
    public const LABEL_KINDS  = [self::LABEL_PREF, self::LABEL_ALT, self::LABEL_HIDDEN];

    private const RELATED = 'related';

    private const MAX_DEPTH_N = 10000;

    /** BCP 47, the shapes a site uses: language, optional script, optional region — `it`, `de-CH`, `zh-Hant-TW`, `es-419`. */
    private const LANG_PATTERN = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|[0-9]{3}))?$/';

    private readonly Table $taxonomies;
    private readonly Table $terms;
    private readonly Table $mappings;
    private readonly Table $labels;

    /** @var array<string, int> scheme => how many batch() calls are open on it */
    private array $batch_depth_by_scheme = [];

    public function __construct(private readonly DataManager $dm)
    {
        [$this->taxonomies, $this->terms, $this->mappings, $this->labels] = Schema::tables($dm->get_driver()->get_dialect_name());
    }

    /** Create the four tables and their indexes; safe to run again, and how an older install gets the labels table. */
    public function install(): void
    {
        $this->dm->create_tables($this->taxonomies, $this->terms, $this->mappings, $this->labels);
    }

    /** @return array{0: Table, 1: Table, 2: Table, 3: Table} taxonomies, terms, mappings, labels — for a caller's own joins */
    public function tables(): array
    {
        return [$this->taxonomies, $this->terms, $this->mappings, $this->labels];
    }

    /**
     * Declare a taxonomy before its first term. Idempotent; its kind may change only while it has never had
     * terms; a null label keeps the one it has.
     */
    public function declare_taxonomy(string $collection_c, string $taxonomy_c, string $kind_c, ?string $label = null): void
    {
        TermRef::assert_scheme($collection_c, $taxonomy_c);

        if (!in_array($kind_c, [self::KIND_TREE, self::KIND_FLAT], true)) {
            throw new TaxonomyException("Kind \"{$kind_c}\" is neither tree nor flat.");
        }

        $this->dm->with_partition($collection_c, function (DataManager $dm) use ($taxonomy_c, $kind_c, $label): void {
            $existing = $this->kind_row($taxonomy_c);

            if ($existing === null) {
                $dm->insert($this->taxonomies)->values([['taxonomy_c' => $taxonomy_c, 'kind_c' => $kind_c, 'label' => $label ?? '']])->execute();

                return;
            }

            if ($existing['kind_c'] !== $kind_c && $this->has_terms($taxonomy_c)) {
                throw new TaxonomyException(
                    "Taxonomy \"{$taxonomy_c}\" is {$existing['kind_c']} and has (or had, deleted) terms: its kind no longer changes."
                );
            }

            $dm->update($this->taxonomies)->set(['kind_c' => $kind_c] + ($label === null ? [] : ['label' => $label]))
                ->where(eq($this->taxonomy('id'), (int) $existing['id']))->execute();
        });
    }

    /** `tree`, `flat`, or null for an undeclared taxonomy. */
    public function kind_of(string $collection_c, string $taxonomy_c): ?string
    {
        $row = $this->dm->with_partition($collection_c, fn (): ?array => $this->kind_row($taxonomy_c));

        return $row === null ? null : (string) $row['kind_c'];
    }

    /**
     * Define a term, or set its label and parent — idempotent and declarative: a null parent makes it a root, so pass
     * the parent again to keep it. The parent must exist in the same collection and taxonomy; a flat taxonomy takes
     * none; a cycle is refused; a new term, or a term moving, is refused under a deprecated parent (not for new
     * uses) — a term already there stays, a deleted one coming back does not. A soft-deleted term defined again comes
     * back as it was, deprecation and successor included.
     */
    public function define(TermRef|string $ref, string $label, ?string $parent_code = null): int
    {
        $ref  = TermRef::from($ref);
        $kind = $this->kind_of($ref->collection_c, $ref->taxonomy_c)
            ?? throw new TaxonomyException("Taxonomy {$ref->scheme()} is not declared: declare_taxonomy() it first.");

        if ($parent_code !== null && $kind === self::KIND_FLAT) {
            throw new TaxonomyException("{$ref->scheme()} is flat: its terms take no parent.");
        }

        return $this->dm->transaction(fn (): int => $this->dm->with_partition($ref->collection_c, function (DataManager $dm) use ($ref, $label, $parent_code): int {
            $parent_id = null;

            if ($parent_code !== null) {
                $parent    = $this->row($ref->taxonomy_c, $parent_code)
                    ?? throw new TaxonomyException("Parent \"{$parent_code}\" of {$ref} does not exist in {$ref->scheme()}.");
                $parent_id = (int) $parent['id'];
            }

            $existing = $this->row($ref->taxonomy_c, $ref->code, true);
            $moving   = $existing === null || $existing['deleted_dt'] !== null || (int) $existing['parent_id'] !== (int) $parent_id;

            if ($moving && isset($parent) && $parent['deprecated_dt'] !== null) {
                throw new TaxonomyException("\"{$parent_code}\" is deprecated: {$ref} cannot be put under it.");
            }

            if ($existing === null) {
                $dm->insert($this->terms)->values([[
                    'taxonomy_c' => $ref->taxonomy_c, 'code' => $ref->code, 'label' => $label, 'parent_id' => $parent_id,
                ]])->execute();
                $id = (int) $this->row($ref->taxonomy_c, $ref->code)['id'];
            } else {
                $id = (int) $existing['id'];

                if ($parent_id !== null && $this->reaches($parent_id, $id)) {
                    throw new TaxonomyException("{$ref} under \"{$parent_code}\" would be a cycle: \"{$parent_code}\" is already under it.");
                }

                $dm->update($this->terms)->set(['label' => $label, 'parent_id' => $parent_id, 'deleted_dt' => null])
                    ->where(eq($this->term('id'), $id))->execute();
            }

            $this->renumber_unless_batched($ref->collection_c, $ref->taxonomy_c);

            return $id;
        }));
    }

    /**
     * Run `$work` as one transaction on one taxonomy, renumbering it once at the end instead of after every define()
     * — what a sync of a whole tree uses. Inside, the numbers are those of before the batch.
     *
     * @return mixed what `$work` returned
     */
    public function batch(string $collection_c, string $taxonomy_c, callable $work)
    {
        TermRef::assert_scheme($collection_c, $taxonomy_c);
        $scheme = "{$collection_c}:{$taxonomy_c}";

        return $this->dm->transaction(function () use ($scheme, $collection_c, $taxonomy_c, $work) {
            $this->batch_depth_by_scheme[$scheme] = ($this->batch_depth_by_scheme[$scheme] ?? 0) + 1;

            try {
                $result = $work($this);
            } finally {
                if (--$this->batch_depth_by_scheme[$scheme] === 0) {
                    unset($this->batch_depth_by_scheme[$scheme]);
                }
            }

            $this->renumber_unless_batched($collection_c, $taxonomy_c);

            return $result;
        });
    }

    /**
     * Mark a term deprecated: still valid for whoever already uses it, not offered for new uses. The optional
     * successor must be a live term of the same taxonomy. Deprecating again keeps the first date; a successor given
     * replaces the one it had, none keeps it.
     */
    public function deprecate(TermRef|string $ref, TermRef|string|null $replaced_by = null): void
    {
        $ref         = TermRef::from($ref);
        $replaced_by = $replaced_by === null ? null : TermRef::from($replaced_by);

        $this->dm->with_partition($ref->collection_c, function (DataManager $dm) use ($ref, $replaced_by): void {
            $row = $this->row($ref->taxonomy_c, $ref->code) ?? throw new TaxonomyException("{$ref} does not exist.");
            $successor_id = null;

            if ($replaced_by !== null) {
                $successor = $replaced_by->in_scheme_of($ref) ? $this->row($ref->taxonomy_c, $replaced_by->code) : null;

                if ($successor === null || $successor['deprecated_dt'] !== null || (int) $successor['id'] === (int) $row['id']) {
                    throw new TaxonomyException("{$replaced_by} cannot replace {$ref}: it must be another live term of {$ref->scheme()}.");
                }

                $successor_id = (int) $successor['id'];
            }

            $dm->update($this->terms)->set([
                'deprecated_dt'  => $row['deprecated_dt'] ?? date('Y-m-d H:i:s'),
                'replaced_by_id' => $successor_id ?? $row['replaced_by_id'],
            ])
                ->where(eq($this->term('id'), (int) $row['id']))->execute();
        });
    }

    /**
     * Soft-delete a term — refused while it has children, any mapping in or out, or replaces a term: deprecate it
     * instead. This
     * library cannot know whether a card or a grant elsewhere uses the term; that is why the rule for callers is
     * "deprecate, don't delete", and why the row stays recoverable (define() it again).
     */
    public function delete(TermRef|string $ref): void
    {
        $ref = TermRef::from($ref);

        $this->dm->transaction(fn () => $this->dm->with_partition($ref->collection_c, function (DataManager $dm) use ($ref): void {
            $row = $this->row($ref->taxonomy_c, $ref->code) ?? throw new TaxonomyException("{$ref} does not exist.");
            $id  = (int) $row['id'];

            $children = $dm->select()->from($this->terms)->where(eq($this->term('parent_id'), $id))->limit(1)->execute();
            $links    = $dm->select()->from($this->mappings)->across_all()
                ->where(or_(eq($this->mapping('from_term_id'), $id), eq($this->mapping('to_term_id'), $id)))->limit(1)->execute();

            // with_trashed(): a deleted predecessor comes back as it was, successor included (define()).
            $replaces = $dm->select()->from($this->terms)->with_trashed()->where(eq($this->term('replaced_by_id'), $id))->limit(1)->execute();

            if ($children !== [] || $links !== [] || $replaces !== []) {
                $why = $children !== [] ? 'has children' : ($links !== [] ? 'has mappings' : 'replaces ' . $replaces[0]['code']);
                throw new TaxonomyException("{$ref} {$why}: deprecate it instead.");
            }

            $dm->update($this->terms)->set(['lft_n' => null, 'rgt_n' => null, 'depth_n' => null])->where(eq($this->term('id'), $id))->execute();
            $dm->delete($this->terms)->where(eq($this->term('id'), $id))->execute();

            $this->renumber_unless_batched($ref->collection_c, $ref->taxonomy_c);
        }));
    }

    /**
     * Map one term to another (SKOS). Written in `$from`'s collection: whoever maps owns the mapping, and
     * permissions never follow it. The five `…Match` relations link different schemes, `related` two terms of
     * the same one, and is symmetric. Idempotent.
     */
    public function map(TermRef|string $from, TermRef|string $to, string $relation_c): void
    {
        $from = TermRef::from($from);
        $to   = TermRef::from($to);

        if (!in_array($relation_c, self::RELATIONS, true)) {
            throw new TaxonomyException("\"{$relation_c}\" is not a SKOS relation: " . implode(', ', self::RELATIONS) . '.');
        }

        if (($relation_c === self::RELATED) !== $from->in_scheme_of($to)) {
            throw new TaxonomyException($relation_c === self::RELATED
                ? "related links two terms of one taxonomy; {$from} and {$to} are in different ones — use a …Match relation."
                : "{$relation_c} links different taxonomies; {$from} and {$to} are in the same one — use related.");
        }

        $from_row = $this->find_row($from) ?? throw new TaxonomyException("{$from} does not exist.");
        $to_row   = $this->find_row($to) ?? throw new TaxonomyException("{$to} does not exist.");

        if ($to_row['deprecated_dt'] !== null) {
            throw new TaxonomyException("{$to} is deprecated: map to its successor.");
        }

        if ($relation_c === self::RELATED && $from_row['deprecated_dt'] !== null) {
            throw new TaxonomyException("{$from} is deprecated, and related is symmetric: map its successor.");
        }

        if ((int) $from_row['id'] === (int) $to_row['id']) {
            throw new TaxonomyException("{$from} cannot be mapped to itself.");
        }

        $this->dm->with_partition($from->collection_c, function (DataManager $dm) use ($from_row, $to_row, $relation_c): void {
            $pairs = [[(int) $from_row['id'], (int) $to_row['id']]];

            if ($relation_c === self::RELATED) {
                $pairs[] = [(int) $to_row['id'], (int) $from_row['id']];
            }

            foreach ($pairs as [$a, $b]) {
                if ($dm->select()->from($this->mappings)->where(and_(eq($this->mapping('from_term_id'), $a),
                    eq($this->mapping('to_term_id'), $b), eq($this->mapping('relation_c'), $relation_c)))->execute() !== []) {
                    return;
                }
            }

            $dm->insert($this->mappings)->values([[
                'from_term_id' => (int) $from_row['id'], 'to_term_id' => (int) $to_row['id'], 'relation_c' => $relation_c,
            ]])->execute();
        });
    }

    /**
     * The links that start at `$ref`: its own collection's mappings from it, and `related` either way — each read
     * from `$ref`.
     *
     * @return list<array{from: string, to: string, relation_c: string}>
     */
    public function mappings_from(TermRef|string $ref): array
    {
        return $this->links(TermRef::from($ref), true);
    }

    /**
     * The links that end at `$ref`, from every collection — explicitly across all of them — and `related` either way.
     *
     * @return list<array{from: string, to: string, relation_c: string}>
     */
    public function mappings_to(TermRef|string $ref): array
    {
        return $this->links(TermRef::from($ref), false);
    }

    /**
     * Set every label of a term, in every language — declarative, like define(): a language left out loses its
     * labels, `[]` removes them all. Per language at most one `pref`, any number of `alt` and `hidden`, in the order
     * given; the three are disjoint (SKOS), compared as match_form() compares, and a label listed twice is refused
     * too — it is a typo, not a wish. Unchanged rows are not rewritten. Deprecated terms take labels: a reference held
     * elsewhere must still be named.
     *
     * Two terms may share a label: "Mystery" under books and under films is the normal case, not an error.
     *
     * @param array<string, array{pref?: string, alt?: list<string>, hidden?: list<string>}> $by_lang lang_c => labels
     */
    public function set_labels(TermRef|string $ref, array $by_lang): void
    {
        $ref  = TermRef::from($ref);
        $want = [];

        foreach ($by_lang as $lang_c => $labels) {
            $lang_c = (string) $lang_c;

            if (preg_match(self::LANG_PATTERN, $lang_c) !== 1) {
                throw new TaxonomyException("\"{$lang_c}\" is not a language tag like it, en, de-CH or zh-Hant.");
            }

            if (!is_array($labels) || array_diff(array_keys($labels), self::LABEL_KINDS) !== []) {
                throw new TaxonomyException("Labels of {$ref} in {$lang_c}: only " . implode(', ', self::LABEL_KINDS) . '.');
            }

            $seen = [];

            foreach (self::LABEL_KINDS as $kind_c) {
                if (!isset($labels[$kind_c])) {
                    continue;
                }

                if ($kind_c === self::LABEL_PREF ? !is_string($labels[$kind_c]) : !is_array($labels[$kind_c]) || !array_is_list($labels[$kind_c])) {
                    throw new TaxonomyException("The {$kind_c} label of {$ref} in {$lang_c}: " . ($kind_c === self::LABEL_PREF ? 'one string.' : 'a list of strings.'));
                }

                foreach (array_values((array) $labels[$kind_c]) as $sort_n => $label) {
                    $label = is_string($label) ? trim($label) : '';
                    $match = self::match_form($label);

                    if ($match === '' || mb_strlen($label) > 255) {
                        throw new TaxonomyException("A {$kind_c} label of {$ref} in {$lang_c} is empty, not a string, or longer than 255.");
                    }

                    if (isset($seen[$match])) {
                        throw new TaxonomyException("\"{$label}\" is a label of {$ref} in {$lang_c} twice ({$seen[$match]} and {$kind_c}).");
                    }

                    $seen[$match] = $kind_c;
                    $want["{$lang_c}\t{$kind_c}\t{$label}"] = [
                        'lang_c' => $lang_c, 'kind_c' => $kind_c, 'label' => $label, 'match_c' => $match, 'sort_n' => $sort_n,
                    ];
                }
            }
        }

        $this->dm->transaction(fn () => $this->dm->with_partition($ref->collection_c, function (DataManager $dm) use ($ref, $want): void {
            $id = (int) ($this->row($ref->taxonomy_c, $ref->code)['id'] ?? throw new TaxonomyException("{$ref} does not exist."));

            foreach ($dm->select()->from($this->labels)->where(eq($this->label('term_id'), $id))->execute() as $row) {
                $key = "{$row['lang_c']}\t{$row['kind_c']}\t{$row['label']}";

                if (isset($want[$key]) && (int) $row['sort_n'] === $want[$key]['sort_n']) {
                    unset($want[$key]);

                    continue;
                }

                $dm->delete($this->labels)->where(eq($this->label('id'), (int) $row['id']))->execute();
            }

            if ($want !== []) {
                $dm->insert($this->labels)->values(array_map(static fn (array $w): array => ['term_id' => $id] + $w, array_values($want)))->execute();
            }
        }));
    }

    /**
     * A term's labels, by language in byte order: `['it' => ['pref' => 'Giallo', 'alt' => […], 'hidden' => […]]]`,
     * every kind present (`pref` null when the language has only synonyms). [] for an unknown term.
     *
     * @return array<string, array{pref: ?string, alt: list<string>, hidden: list<string>}>
     */
    public function labels_of(TermRef|string $ref): array
    {
        $ref = TermRef::from($ref);
        $row = $this->find_row($ref);

        if ($row === null) {
            return [];
        }

        $rows = $this->dm->select()->from($this->labels)->within($ref->collection_c)
            ->where(eq($this->label('term_id'), (int) $row['id']))->execute();

        return self::label_shape($rows)[(int) $row['id']] ?? [];
    }

    /**
     * Every term's labels in one read, by code — what a page listing a whole taxonomy needs. Deleted terms are left
     * out, deprecated ones kept; terms without labels too.
     *
     * @return array<string, array<string, array{pref: ?string, alt: list<string>, hidden: list<string>}>>
     */
    public function labels(string $collection_c, string $taxonomy_c): array
    {
        TermRef::assert_scheme($collection_c, $taxonomy_c);

        return $this->dm->with_partition($collection_c, function (DataManager $dm) use ($taxonomy_c): array {
            $code_by_id = array_column(
                $dm->select()->from($this->terms)->where(eq($this->term('taxonomy_c'), $taxonomy_c))->execute(),
                'code',
                'id'
            );

            if ($code_by_id === []) {
                return [];
            }

            $rows = $dm->select()->from($this->labels)->where(in_($this->label('term_id'), array_map('intval', array_keys($code_by_id))))->execute();
            $out  = [];

            foreach (self::label_shape($rows) as $id => $by_lang) {
                $out[(string) $code_by_id[$id]] = $by_lang;
            }

            ksort($out, SORT_STRING);

            return $out;
        });
    }

    /**
     * The name to show: the `pref` label of the first language in `$langs` that has one — pass a fallback chain,
     * `Italix\I18n\Locale::chain('de-CH', 'it')` gives `['de-CH', 'de', 'it']` — else the term's own label. Null for
     * an unknown term.
     *
     * @param list<string> $langs
     */
    public function pref_label(TermRef|string $ref, array $langs): ?string
    {
        $ref = TermRef::from($ref);
        $row = $this->find_row($ref);

        if ($row === null) {
            return null;
        }

        $labels = $this->labels_of($ref);

        foreach ($langs as $lang_c) {
            if (($labels[$lang_c]['pref'] ?? null) !== null) {
                return $labels[$lang_c]['pref'];
            }
        }

        return (string) $row['label'];
    }

    /**
     * The terms of a taxonomy that carry `$text` as a label, compared as match_form() compares — case and spacing
     * aside, accents not. In one language or all, of the kinds asked: `hidden` alone resolves a legacy name, `pref`
     * and `alt` together are what a search box means. Deleted terms are left out, deprecated ones flagged.
     *
     * @param  list<string> $kinds
     * @return list<array{code: string, lang_c: string, kind_c: string, label: string, deprecated: bool}> by code, language, kind
     */
    public function find_by_label(string $collection_c, string $taxonomy_c, string $text, ?string $lang_c = null, array $kinds = self::LABEL_KINDS): array
    {
        TermRef::assert_scheme($collection_c, $taxonomy_c);
        $match = self::match_form($text);

        if ($match === '' || $kinds === []) {
            return [];
        }

        return $this->dm->with_partition($collection_c, function (DataManager $dm) use ($taxonomy_c, $match, $lang_c, $kinds): array {
            $where = [eq($this->label('match_c'), $match), in_($this->label('kind_c'), array_values($kinds))];

            if ($lang_c !== null) {
                $where[] = eq($this->label('lang_c'), $lang_c);
            }

            $rows = $dm->select()->from($this->labels)->where(and_(...$where))->execute();

            if ($rows === []) {
                return [];
            }

            $terms = [];
            foreach ($dm->select()->from($this->terms)->where(and_(
                eq($this->term('taxonomy_c'), $taxonomy_c),
                in_($this->term('id'), array_values(array_unique(array_map(static fn (array $r): int => (int) $r['term_id'], $rows))))
            ))->execute() as $t) {
                $terms[(int) $t['id']] = $t;
            }

            $out = [];
            foreach ($rows as $r) {
                $t = $terms[(int) $r['term_id']] ?? null;

                // match_c holds bytes, and a MySQL comparison may still have folded them: check here too.
                if ($t !== null && (string) $r['match_c'] === $match) {
                    $out[] = [
                        'code' => (string) $t['code'], 'lang_c' => (string) $r['lang_c'], 'kind_c' => (string) $r['kind_c'],
                        'label' => (string) $r['label'], 'deprecated' => $t['deprecated_dt'] !== null,
                    ];
                }
            }

            $rank = array_flip(self::LABEL_KINDS);
            usort($out, static fn (array $a, array $b): int => [$a['code'], $a['lang_c'], $rank[$a['kind_c']]] <=> [$b['code'], $b['lang_c'], $rank[$b['kind_c']]]);

            return $out;
        });
    }

    /**
     * A label as labels are compared: Unicode NFC when ext-intl is there, lower case, runs of white space one space,
     * trimmed. Accents stay — "Città" and "Citta" are different words, and folding them is a search engine's choice.
     */
    public static function match_form(string $label): string
    {
        if (class_exists(\Normalizer::class)) {
            $label = (string) \Normalizer::normalize($label, \Normalizer::FORM_C);
        }

        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($label, 'UTF-8')));
    }

    /**
     * The terms of a taxonomy in walk order — deprecated ones only when asked: they are not offered for new uses.
     *
     * @return list<array{code: string, label: string, parent_code: ?string, depth_n: int, lft_n: int, rgt_n: int, deprecated: bool}>
     */
    public function terms(string $collection_c, string $taxonomy_c, bool $include_deprecated = false): array
    {
        $rows = $this->dm->with_partition($collection_c, fn (DataManager $dm): array => $dm->select()->from($this->terms)
            ->where(eq($this->term('taxonomy_c'), $taxonomy_c))->order_by($this->term('lft_n'), $this->term('code'))->execute());

        $code_by_id = array_column($rows, 'code', 'id');
        $out        = [];

        foreach ($rows as $row) {
            if ($row['deprecated_dt'] !== null && !$include_deprecated) {
                continue;
            }

            $out[] = self::shape($row, $code_by_id);
        }

        return $out;
    }

    /**
     * One term, or null — deprecated ones included, since a reference someone holds must still resolve.
     *
     * @return array{code: string, label: string, parent_code: ?string, depth_n: int, lft_n: int, rgt_n: int, deprecated: bool}|null
     */
    public function find(TermRef|string $ref): ?array
    {
        $ref = TermRef::from($ref);
        $row = $this->find_row($ref);

        if ($row === null) {
            return null;
        }

        $parent = $row['parent_id'] === null ? [] : $this->dm->select()->from($this->terms)->within($ref->collection_c)
            ->where(eq($this->term('id'), (int) $row['parent_id']))->execute();

        return self::shape($row, array_column($parent, 'code', 'id'));
    }

    /**
     * The term and every term under it, in walk order; [] for an unknown term (the contract `VectorStore` already
     * has). Reads the numbers as of the last renumber.
     *
     * @return list<string> codes
     */
    public function descendants_of(TermRef|string $ref): array
    {
        $ref = TermRef::from($ref);
        $row = $this->find_row($ref);

        if ($row === null || $row['lft_n'] === null) {
            return [];
        }

        return array_column($this->dm->select()->from($this->terms)->within($ref->collection_c)->where(and_(
            eq($this->term('taxonomy_c'), $ref->taxonomy_c),
            gte($this->term('lft_n'), (int) $row['lft_n']),
            lte($this->term('lft_n'), (int) $row['rgt_n'])
        ))->order_by($this->term('lft_n'))->execute(), 'code');
    }

    /**
     * The path to the term, root first, the term itself last; [] for an unknown term.
     *
     * @return list<string> codes
     */
    public function ancestors_of(TermRef|string $ref): array
    {
        $ref = TermRef::from($ref);
        $row = $this->find_row($ref);

        if ($row === null || $row['lft_n'] === null) {
            return [];
        }

        return array_column($this->dm->select()->from($this->terms)->within($ref->collection_c)->where(and_(
            eq($this->term('taxonomy_c'), $ref->taxonomy_c),
            lte($this->term('lft_n'), (int) $row['lft_n']),
            gte($this->term('rgt_n'), (int) $row['rgt_n'])
        ))->order_by($this->term('lft_n'))->execute(), 'code');
    }

    /** Whether `$ref` is `$ancestor` or lies under it; false when either is unknown or they are in different taxonomies. */
    public function is_under(TermRef|string $ref, TermRef|string $ancestor): bool
    {
        $ref      = TermRef::from($ref);
        $ancestor = TermRef::from($ancestor);

        return $ref->in_scheme_of($ancestor) && $this->tree($ref->collection_c, $ref->taxonomy_c)->is_under($ref->code, $ancestor->code);
    }

    /** One taxonomy's intervals in memory, for many tests in one request. Deprecated terms included. */
    public function tree(string $collection_c, string $taxonomy_c): Tree
    {
        $rows = $this->dm->with_partition($collection_c, fn (DataManager $dm): array => $dm->select()->from($this->terms)
            ->where(and_(eq($this->term('taxonomy_c'), $taxonomy_c), gte($this->term('lft_n'), 1)))->execute());

        $interval_by_code = [];

        foreach ($rows as $row) {
            $interval_by_code[(string) $row['code']] = ['lft_n' => (int) $row['lft_n'], 'rgt_n' => (int) $row['rgt_n'], 'depth_n' => (int) $row['depth_n']];
        }

        return new Tree($interval_by_code);
    }

    /**
     * "Is this code that one, or under it?" as a closure over a qualified taxonomy key (`collection:taxonomy`) — what an
     * access check takes (`Italix\Acl\Dnf::satisfies()`), where a grant on a category covers its subtree. Each
     * taxonomy's `tree()` is read on first use and held for the closure's life: build one per request and never keep
     * it — the numbers change on every renumber. Equal codes are always contained, known or not; a key that names no
     * collection contains nothing else.
     *
     * @return \Closure(string, string, string): bool the taxonomy key, the record's code, a granted code
     */
    public function containment(): \Closure
    {
        /** @var array<string, Tree> $trees */
        $trees = [];

        return function (string $taxonomy_c, string $code, string $granted) use (&$trees): bool {
            if ($code === $granted) {
                return true;
            }

            $parts = explode(':', $taxonomy_c, 2);

            if (count($parts) !== 2) {
                return false;
            }

            $trees[$taxonomy_c] ??= $this->tree($parts[0], $parts[1]);

            return $trees[$taxonomy_c]->is_under($code, $granted);
        };
    }

    /**
     * Renumber one taxonomy now, whole — what define(), delete() and batch() call. Only rows whose numbers changed
     * are written, and their `update_dt` is kept: a renumber is index upkeep, not an edit.
     */
    public function renumber(string $collection_c, string $taxonomy_c): void
    {
        TermRef::assert_scheme($collection_c, $taxonomy_c);

        $this->dm->with_partition($collection_c, function (DataManager $dm) use ($taxonomy_c): void {
            $rows = $dm->select()->from($this->terms)->where(eq($this->term('taxonomy_c'), $taxonomy_c))->execute();

            $parent_by_id = [];
            $code_by_id   = [];

            foreach ($rows as $row) {
                $parent_by_id[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
                $code_by_id[(int) $row['id']]   = (string) $row['code'];
            }

            $numbers = Numbering::number($parent_by_id, $code_by_id);

            foreach ($rows as $row) {
                $new = $numbers[(int) $row['id']];

                if ($row['lft_n'] !== null
                    && [(int) $row['lft_n'], (int) $row['rgt_n'], (int) $row['depth_n']] === [$new['lft_n'], $new['rgt_n'], $new['depth_n']]) {
                    continue;
                }

                $dm->update($this->terms)->set($new + ['update_dt' => $row['update_dt']])
                    ->where(eq($this->term('id'), (int) $row['id']))->execute();
            }
        });
    }

    private function term(string $name): Column
    {
        return self::column($this->terms, $name);
    }

    private function mapping(string $name): Column
    {
        return self::column($this->mappings, $name);
    }

    private function label(string $name): Column
    {
        return self::column($this->labels, $name);
    }

    private function taxonomy(string $name): Column
    {
        return self::column($this->taxonomies, $name);
    }

    private static function column(Table $table, string $name): Column
    {
        return $table->get_column($name) ?? throw new \LogicException("{$table->get_name()} has no column {$name}.");
    }

    private function renumber_unless_batched(string $collection_c, string $taxonomy_c): void
    {
        if (!isset($this->batch_depth_by_scheme["{$collection_c}:{$taxonomy_c}"])) {
            $this->renumber($collection_c, $taxonomy_c);
        }
    }

    /** @return array<string, mixed>|null inside the current partition */
    private function kind_row(string $taxonomy_c): ?array
    {
        return $this->dm->select()->from($this->taxonomies)->where(eq($this->taxonomy('taxonomy_c'), $taxonomy_c))->execute()[0] ?? null;
    }

    private function has_terms(string $taxonomy_c): bool
    {
        return $this->dm->select()->from($this->terms)->with_trashed()->where(eq($this->term('taxonomy_c'), $taxonomy_c))->limit(1)->execute() !== [];
    }

    /** @return array<string, mixed>|null inside the current partition */
    private function row(string $taxonomy_c, string $code, bool $with_trashed = false): ?array
    {
        $query = $this->dm->select()->from($this->terms)
            ->where(and_(eq($this->term('taxonomy_c'), $taxonomy_c), eq($this->term('code'), $code)));

        return ($with_trashed ? $query->with_trashed() : $query)->execute()[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function find_row(TermRef $ref): ?array
    {
        return $this->dm->with_partition($ref->collection_c, fn (): ?array => $this->row($ref->taxonomy_c, $ref->code));
    }

    /** Whether walking up from `$start_id` reaches `$target_id` — the cycle check, independent of the numbers. */
    private function reaches(int $start_id, int $target_id): bool
    {
        for ($id = $start_id, $step_n = 0; $id !== null && $step_n < self::MAX_DEPTH_N; $step_n++) {
            if ($id === $target_id) {
                return true;
            }

            $parent = $this->dm->select()->from($this->terms)->where(eq($this->term('id'), $id))->execute()[0]['parent_id'] ?? null;
            $id     = $parent === null ? null : (int) $parent;
        }

        return false;
    }

    /** @return list<array{from: string, to: string, relation_c: string}> */
    private function links(TermRef $ref, bool $outgoing): array
    {
        $row = $this->find_row($ref);

        if ($row === null) {
            return [];
        }

        $id  = (int) $row['id'];
        $all = $this->dm->select()->from($this->mappings)->across_all();

        $mine = $outgoing
            ? $all->within($ref->collection_c)->where(eq($this->mapping('from_term_id'), $id))->execute()
            : $all->where(eq($this->mapping('to_term_id'), $id))->execute();
        $related = $all->within($ref->collection_c)->where(and_(eq($this->mapping('relation_c'), self::RELATED),
            $outgoing ? eq($this->mapping('to_term_id'), $id) : eq($this->mapping('from_term_id'), $id)))->execute();

        $pairs = [];

        foreach ($mine as $m) {
            $pairs[] = [(int) $m['from_term_id'], (int) $m['to_term_id'], (string) $m['relation_c']];
        }

        foreach ($related as $m) {
            $pairs[] = $outgoing
                ? [$id, (int) $m['from_term_id'], self::RELATED]
                : [(int) $m['to_term_id'], $id, self::RELATED];
        }

        $ids = array_values(array_unique(array_merge(array_column($pairs, 0), array_column($pairs, 1))));
        $ref_by_id = [];

        if ($ids !== []) {
            foreach ($this->dm->select()->from($this->terms)->across_all()->where(in_($this->term('id'), $ids))->execute() as $t) {
                $ref_by_id[(int) $t['id']] = (string) TermRef::of((string) $t['collection_c'], (string) $t['taxonomy_c'], (string) $t['code']);
            }
        }

        $out = [];

        foreach ($pairs as [$a, $b, $relation_c]) {
            $out[] = ['from' => $ref_by_id[$a], 'to' => $ref_by_id[$b], 'relation_c' => $relation_c];
        }

        usort($out, static fn (array $x, array $y): int => [$x['from'], $x['to'], $x['relation_c']] <=> [$y['from'], $y['to'], $y['relation_c']]);

        return $out;
    }

    /**
     * @param  list<array<string, mixed>> $rows label rows
     * @return array<int, array<string, array{pref: ?string, alt: list<string>, hidden: list<string>}>> term id => lang_c =>
     */
    private static function label_shape(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => (int) $a['sort_n'] <=> (int) $b['sort_n'] ?: (int) $a['id'] <=> (int) $b['id']);
        $out = [];

        foreach ($rows as $r) {
            $by_lang = &$out[(int) $r['term_id']][(string) $r['lang_c']];
            $by_lang ??= ['pref' => null, 'alt' => [], 'hidden' => []];

            if ($r['kind_c'] === self::LABEL_PREF) {
                $by_lang['pref'] = (string) $r['label'];
            } else {
                $by_lang[(string) $r['kind_c']][] = (string) $r['label'];
            }

            unset($by_lang);
        }

        foreach ($out as &$by_lang) {
            ksort($by_lang, SORT_STRING);
        }
        unset($by_lang);

        return $out;
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<int|string, string> $code_by_id
     * @return array{code: string, label: string, parent_code: ?string, depth_n: int, lft_n: int, rgt_n: int, deprecated: bool}
     */
    private static function shape(array $row, array $code_by_id): array
    {
        return [
            'code'        => (string) $row['code'],
            'label'       => (string) $row['label'],
            'parent_code' => $row['parent_id'] === null ? null : ($code_by_id[$row['parent_id']] ?? null),
            'depth_n'     => (int) $row['depth_n'],
            'lft_n'       => (int) $row['lft_n'],
            'rgt_n'       => (int) $row['rgt_n'],
            'deprecated'  => $row['deprecated_dt'] !== null,
        ];
    }
}
