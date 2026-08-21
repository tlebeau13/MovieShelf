<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Pull one week of one NYT bestseller list (#6).
 *
 * Implements IngestionMessage, so messenger.yaml routes it async with no routing
 * line of its own.
 *
 * The week is carried as a string, not a DateTimeImmutable: the message is
 * serialized into the Doctrine transport, and a date string survives a worker
 * running in a different timezone unchanged.
 */
final readonly class IngestNytWeek implements IngestionMessage
{
    /**
     * @param string      $listName      NYT's list slug
     * @param string|null $publishedDate `Y-m-d`, snapped by the API to the list
     *                                   published that week; null asks for the
     *                                   current list, which is what the scheduler does
     */
    public function __construct(
        public string $listName = 'hardcover-fiction',
        public ?string $publishedDate = null,
    ) {
    }

    public function week(): ?\DateTimeImmutable
    {
        if (null === $this->publishedDate) {
            return null;
        }

        return new \DateTimeImmutable($this->publishedDate);
    }
}
