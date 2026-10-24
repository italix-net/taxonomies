<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Taxonomies;

use Italix\Orm\Schema\Column;
use Italix\Orm\Schema\Table;

use function Italix\Orm\Schema\{datetime, integer, serial, varbinary, varchar};

/**
 * The four tables, each `partitioned_by('collection_c')`: a taxonomy, its terms, their labels per language and the
 * mappings its owner made all live in one collection. Unique keys lead with the collection, so each collection has
 * its own codes.
 */
final class Schema
{
    public const TAXONOMIES = 'ix_taxon_taxonomies';
    public const TERMS      = 'ix_taxon_terms';
    public const MAPPINGS   = 'ix_taxon_mappings';
    public const LABELS     = 'ix_taxon_labels';

    private function __construct()
    {
    }

    /** @return array{0: Table, 1: Table, 2: Table, 3: Table} taxonomies, terms, mappings, labels */
    public static function tables(string $dialect): array
    {
        $taxonomies = (new Table(self::TAXONOMIES, [
            'id'           => serial(),
            'collection_c' => varchar(191)->not_null()->default(''),
            'taxonomy_c'   => varchar(191)->not_null(),
            'kind_c'       => varchar(10)->not_null(),
            'label'        => varchar(255)->not_null()->default(''),
            'insert_dt'    => datetime(),
            'update_dt'    => datetime(),
        ], $dialect))
            ->partitioned_by('collection_c')
            ->timestamps('insert_dt', 'update_dt')
            ->add_unique(self::TAXONOMIES . '_scheme', ['collection_c', 'taxonomy_c']);

        $terms = (new Table(self::TERMS, [
            'id'             => serial(),
            'collection_c'   => varchar(191)->not_null()->default(''),
            'taxonomy_c'     => varchar(191)->not_null(),
            'code'           => self::code_column($dialect),
            'label'          => varchar(255)->not_null()->default(''),
            'parent_id'      => integer(),
            'lft_n'          => integer(),
            'rgt_n'          => integer(),
            'depth_n'        => integer(),
            'deprecated_dt'  => datetime(),
            'replaced_by_id' => integer(),
            'insert_dt'      => datetime(),
            'update_dt'      => datetime(),
            'deleted_dt'     => datetime(),
        ], $dialect))
            ->partitioned_by('collection_c')
            ->timestamps('insert_dt', 'update_dt')
            ->soft_deletes('deleted_dt')
            ->add_unique(self::TERMS . '_code', ['collection_c', 'taxonomy_c', 'code'])
            ->add_index(self::TERMS . '_interval', ['collection_c', 'taxonomy_c', 'lft_n'])
            ->add_index(self::TERMS . '_parent', ['parent_id']);

        $mappings = (new Table(self::MAPPINGS, [
            'id'           => serial(),
            'collection_c' => varchar(191)->not_null()->default(''),
            'from_term_id' => integer()->not_null(),
            'to_term_id'   => integer()->not_null(),
            'relation_c'   => varchar(20)->not_null(),
            'insert_dt'    => datetime(),
            'update_dt'    => datetime(),
        ], $dialect))
            ->partitioned_by('collection_c')
            ->timestamps('insert_dt', 'update_dt')
            ->add_unique(self::MAPPINGS . '_link', ['from_term_id', 'to_term_id', 'relation_c'])
            ->add_index(self::MAPPINGS . '_to', ['to_term_id']);

        // SKOS labels: `kind_c` is pref, alt or hidden; `match_c` the label as compared (Taxonomies::match_form()),
        // byte for byte like a code, so a lookup answers the same on every database.
        $labels = (new Table(self::LABELS, [
            'id'           => serial(),
            'collection_c' => varchar(191)->not_null()->default(''),
            'term_id'      => integer()->not_null(),
            'lang_c'       => varchar(35)->not_null(),
            'kind_c'       => varchar(10)->not_null(),
            'label'        => varchar(255)->not_null(),
            'match_c'      => self::code_column($dialect, 255),
            'sort_n'       => integer()->not_null()->default(0),
            'insert_dt'    => datetime(),
            'update_dt'    => datetime(),
        ], $dialect))
            ->partitioned_by('collection_c')
            ->timestamps('insert_dt', 'update_dt')
            ->add_index(self::LABELS . '_term', ['term_id', 'lang_c'])
            ->add_index(self::LABELS . '_match', ['collection_c', 'match_c']);

        return [$taxonomies, $terms, $mappings, $labels];
    }

    /**
     * A code compares byte for byte everywhere. On MySQL/MariaDB a VARCHAR takes the table's `_ci` collation, which
     * would make `B` and `b` — or `è` and `e` — one code, so it is VARBINARY there. Not on SQLite: a VARBINARY column
     * gets NUMERIC affinity and would store `007` as 7; its VARCHAR already compares bytes, as PostgreSQL's does.
     */
    private static function code_column(string $dialect, int $length_n = 191): Column
    {
        return ($dialect === 'mysql' ? varbinary($length_n) : varchar($length_n))->not_null();
    }
}
