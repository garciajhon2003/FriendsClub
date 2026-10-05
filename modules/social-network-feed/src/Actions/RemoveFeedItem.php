<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Feed\Actions;

use Liberu\SocialNetwork\Feed\Models\FeedEntry;

/**
 * Takes an item out of every feed, for when the item itself goes away. Like
 * DistributeFeedEntry it is a system action: the caller has already decided
 * the item is gone.
 */
final class RemoveFeedItem
{
    /** @return int how many feed entries were removed */
    public function handle(string $itemType, string $itemId): int
    {
        return FeedEntry::query()->where(['item_type' => $itemType, 'item_id' => $itemId])->delete();
    }
}
