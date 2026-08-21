<?php

declare(strict_types=1);

namespace App\Ingestion\Nyt;

/**
 * One weekly list as NYT returned it (#6).
 *
 * Parsed apart from the HTTP call so the mapping is covered by fixtures and needs
 * no key — see api/README.md.
 */
final readonly class NytList
{
    /**
     * @param \DateTimeImmutable $publishedDate the list's own key, always a Sunday.
     *                                          Not our fetch clock and not
     *                                          `bestsellers_date` (the sales week
     *                                          measured): the API snaps a requested
     *                                          date to this one, which is what makes
     *                                          two crawls of the same week collapse
     *                                          onto the same rows.
     * @param list<NytBook>      $books
     */
    public function __construct(
        public string $listName,
        public \DateTimeImmutable $publishedDate,
        public ?\DateTimeImmutable $previousPublishedDate,
        public array $books,
    ) {
    }

    /**
     * @param array<string, mixed> $payload a decoded `lists/{date}/{list}.json` body
     *
     * @throws NytResponseException when the body is not the shape the writer needs
     */
    public static function fromPayload(array $payload): self
    {
        $results = $payload['results'] ?? null;

        if (!\is_array($results) || !\is_array($results['books'] ?? null)) {
            throw new NytResponseException('Response has no results.books; NYT changed shape or returned an error body.');
        }

        $published = self::date($results, 'published_date')
            ?? throw new NytResponseException('Response has no results.published_date, so the week the rows belong to is unknown.');

        return new self(
            // list_name_encoded is the slug the endpoint takes, so storing it is what
            // lets a row be re-fetched from its own list_name.
            listName: (string) ($results['list_name_encoded'] ?? $results['list_name'] ?? ''),
            publishedDate: $published,
            previousPublishedDate: self::date($results, 'previous_published_date'),
            books: array_values(array_map(NytBook::fromPayload(...), $results['books'])),
        );
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function date(array $results, string $key): ?\DateTimeImmutable
    {
        $value = $results[$key] ?? '';

        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
