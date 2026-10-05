<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Feed\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Liberu\SocialNetwork\Feed\Events\FeedEntryAdded;
use Liberu\SocialNetwork\Feed\Models\FeedEntry;

/**
 * Puts one item in the feeds of many viewers at once: the system side of
 * AddFeedEntry, for fan-out when something is published. It is not gated by
 * the signed-in user, because the author fills other people's feeds (and a
 * scheduled publication has nobody signed in), so callers decide who receives
 * the item. Never call it with ids taken from a request.
 */
final readonly class DistributeFeedEntry
{
    public function __construct(private Dispatcher $events) {}

    /**
     * @param  list<string>  $viewerProfileIds
     * @return int how many feeds received the item
     */
    public function handle(array $viewerProfileIds, string $itemType, string $itemId, float $rank = 0, ?Carbon $visibleAt = null): int
    {
        if ($itemType === '' || ! Str::isUuid($itemId)) {
            throw new InvalidArgumentException('Feed entries require a valid item reference.');
        }
        if (! is_finite($rank) || $rank < -1000000 || $rank > 1000000) {
            throw new InvalidArgumentException('Feed entry rank is outside the supported range.');
        }

        $entries = DB::transaction(fn (): array => array_map(
            fn (string $viewerId): FeedEntry => FeedEntry::query()->updateOrCreate(
                ['viewer_profile_id' => $viewerId, 'item_type' => $itemType, 'item_id' => $itemId],
                ['id' => (string) Str::uuid(), 'rank' => $rank, 'visible_at' => $visibleAt ?? now()],
            ),
            array_values(array_unique($viewerProfileIds)),
        ));

        foreach ($entries as $entry) {
            $this->events->dispatch(new FeedEntryAdded($entry));
        }

        return count($entries);
    }
}
