<?php

declare(strict_types=1);

namespace Lava\Core\Map;

/**
 * A pack's own section of the map: a title, a sentence saying what the table
 * lists, and its rows — facts only that pack knows, such as which listeners an
 * event reaches. See {@see \Lava\Core\Modules\ProvidesMapSection}.
 *
 * The rows go into the map's fingerprint, so they follow ProjectMap's rules:
 * declarations rather than resolved state, and no absolute paths.
 */
final readonly class MapSection
{
    /**
     * @param string $title the section heading, e.g. `Events`
     * @param string $intro one or two sentences on what the table lists and where it is declared
     * @param list<string> $columns
     * @param list<list<string>> $rows each with one cell per column
     */
    public function __construct(
        public string $title,
        public string $intro,
        public array $columns,
        public array $rows,
    ) {
        foreach ($rows as $index => $row) {
            if (count($row) !== count($columns)) {
                throw new \InvalidArgumentException(
                    "Map section '{$title}': row {$index} has " . count($row) . ' cells for ' . count($columns) . ' columns.',
                );
            }
        }
    }
}
