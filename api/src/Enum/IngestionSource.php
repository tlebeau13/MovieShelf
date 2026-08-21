<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The sources #5/#6/#7 ingest from. An enum rather than a free string so a typo
 * cannot quietly create a fourth source that no dashboard ever looks at.
 */
enum IngestionSource: string
{
    case Tmdb = 'tmdb';
    case Nyt = 'nyt';
    case OpenLibrary = 'openlibrary';
}
