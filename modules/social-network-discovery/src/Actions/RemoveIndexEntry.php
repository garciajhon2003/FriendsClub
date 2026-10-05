<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Discovery\Actions;

use Liberu\SocialNetwork\Discovery\Models\DiscoveryIndex;

/**
 * Takes a resource out of the search index, for when it is deleted. A system
 * action, like SyncIndexEntry.
 */
final class RemoveIndexEntry
{
    /** @return bool whether the resource was indexed */
    public function handle(string $resourceType, string $resourceId): bool
    {
        return DiscoveryIndex::query()->where(['resource_type' => $resourceType, 'resource_id' => $resourceId])->delete() > 0;
    }
}
