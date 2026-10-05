<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Activity\Listeners;

use Liberu\SocialNetwork\Discovery\Actions\RemoveIndexEntry;
use Liberu\SocialNetwork\Feed\Actions\RemoveFeedItem;
use Liberu\SocialNetwork\Publishing\Models\Publication;

/**
 * When a publication is deleted, takes it out of every feed and out of the
 * search index. DeletePublication announces nothing, so this hangs off the
 * model's own "deleted" event.
 */
final readonly class WithdrawDeletedPublication
{
    public function __construct(
        private RemoveFeedItem $removeFromFeeds,
        private RemoveIndexEntry $removeFromIndex,
    ) {}

    public function handle(Publication $publication): void
    {
        $this->removeFromFeeds->handle($publication->getMorphClass(), (string) $publication->getKey());
        $this->removeFromIndex->handle($publication->getMorphClass(), (string) $publication->getKey());
    }
}
