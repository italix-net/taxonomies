<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Taxonomies;

/**
 * A term, named in full: `collection:taxonomy:code` — `shop:category:42`, `manual:axis:sterilization`.
 *
 * The collection part is never implied: the `''` collection is written `:status:active`, and `status:active` is an
 * error rather than a default. Collection and taxonomy are kebab-case slugs, so neither holds a `:`; the code is the
 * last part and may.
 */
final class TermRef
{
    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(
        public readonly string $collection_c,
        public readonly string $taxonomy_c,
        public readonly string $code
    ) {
    }

    public static function of(string $collection_c, string $taxonomy_c, string $code): self
    {
        self::assert_scheme($collection_c, $taxonomy_c);

        if ($code === '') {
            throw new TaxonomyException("A term of {$collection_c}:{$taxonomy_c} needs a code.");
        }

        return new self($collection_c, $taxonomy_c, $code);
    }

    public static function parse(string $ref): self
    {
        $parts = explode(':', $ref, 3);

        if (count($parts) !== 3) {
            throw new TaxonomyException(
                "\"{$ref}\" is not collection:taxonomy:code. The collection is never implied: "
                . "write \":{$ref}\" for the '' collection."
            );
        }

        return self::of($parts[0], $parts[1], $parts[2]);
    }

    public static function from(self|string $ref): self
    {
        return $ref instanceof self ? $ref : self::parse($ref);
    }

    /** Refuse a collection or taxonomy that is not a kebab-case slug — `''` is a valid collection. */
    public static function assert_scheme(string $collection_c, string $taxonomy_c): void
    {
        if ($collection_c !== '' && preg_match(self::SLUG, $collection_c) !== 1) {
            throw new TaxonomyException("Collection \"{$collection_c}\" is not a kebab-case slug.");
        }

        if (preg_match(self::SLUG, $taxonomy_c) !== 1) {
            throw new TaxonomyException("Taxonomy \"{$taxonomy_c}\" is not a kebab-case slug.");
        }
    }

    /** The taxonomy this term belongs to, `collection:taxonomy` — the key a grant or a search names. */
    public function scheme(): string
    {
        return "{$this->collection_c}:{$this->taxonomy_c}";
    }

    public function in_scheme_of(self $other): bool
    {
        return $this->collection_c === $other->collection_c && $this->taxonomy_c === $other->taxonomy_c;
    }

    public function __toString(): string
    {
        return "{$this->collection_c}:{$this->taxonomy_c}:{$this->code}";
    }
}
