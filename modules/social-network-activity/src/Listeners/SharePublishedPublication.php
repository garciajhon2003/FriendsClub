<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Activity\Listeners;

use Liberu\SocialNetwork\Activity\PublicationAudience;
use Liberu\SocialNetwork\Discovery\Actions\SyncIndexEntry;
use Liberu\SocialNetwork\Feed\Actions\DistributeFeedEntry;
use Liberu\SocialNetwork\Publishing\Events\PublicationPublished;
use Liberu\SocialNetwork\Publishing\Models\Publication;

/**
 * When a publication goes out (now or on schedule), puts it in the feeds of
 * its audience and in the search index.
 */
final readonly class SharePublishedPublication
{
    /** Seconds in a day: the feed rank counts days, so newer always ranks higher. */
    private const int SECONDS_PER_DAY = 86400;

    public function __construct(
        private PublicationAudience $audience,
        private DistributeFeedEntry $distribute,
        private SyncIndexEntry $index,
    ) {}

    public function handle(PublicationPublished $event): void
    {
        $publication = $event->publication;
        $publishedAt = $publication->published_at ?? now();

        $this->distribute->handle(
            $this->audience->of($publication),
            $publication->getMorphClass(),
            (string) $publication->getKey(),
            round($publishedAt->getTimestamp() / self::SECONDS_PER_DAY, 6),
            $publishedAt,
        );

        $text = $this->searchableText($publication);
        if ($text !== '') {
            $this->index->handle([
                'resource_type' => $publication->getMorphClass(),
                'resource_id' => (string) $publication->getKey(),
                'owner_profile_id' => (string) $publication->author_profile_id,
                'visibility' => $publication->audience,
                'body' => $text,
                'published_at' => $publishedAt,
            ]);
        }
    }

    private function searchableText(Publication $publication): string
    {
        return trim(collect([$publication->title, $publication->body])->filter()->implode('. '));
    }
}
