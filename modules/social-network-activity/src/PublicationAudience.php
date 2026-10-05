<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Activity;

use Liberu\SocialNetwork\Publishing\Models\Publication;
use Liberu\SocialNetwork\SocialGraph\Models\Block;
use Liberu\SocialNetwork\SocialGraph\Models\Relationship;

/**
 * Whose feed a publication belongs in. The author always; for "public" and
 * "followers" also everyone who follows the author and every friend; for
 * "private" nobody else. Anyone a block stands between, in either direction,
 * is left out.
 */
final class PublicationAudience
{
    /** @return list<string> profile ids */
    public function of(Publication $publication): array
    {
        $author = (string) $publication->author_profile_id;

        if ($publication->audience === 'private') {
            return [$author];
        }

        $followers = Relationship::query()
            ->where(['target_profile_id' => $author, 'relationship_type' => 'follow', 'status' => 'accepted'])
            ->pluck('source_profile_id');

        $friends = Relationship::query()
            ->where(['relationship_type' => 'friend', 'status' => 'accepted'])
            ->where(fn ($query) => $query->where('source_profile_id', $author)->orWhere('target_profile_id', $author))
            ->get(['source_profile_id', 'target_profile_id'])
            ->map(fn (Relationship $friendship): string => (string) ($friendship->source_profile_id === $author ? $friendship->target_profile_id : $friendship->source_profile_id));

        $blocked = Block::query()
            ->where('source_profile_id', $author)->pluck('target_profile_id')
            ->merge(Block::query()->where('target_profile_id', $author)->pluck('source_profile_id'))
            ->map(fn (mixed $id): string => (string) $id);

        return $followers->map(fn (mixed $id): string => (string) $id)
            ->merge($friends)
            ->diff($blocked)
            ->push($author)
            ->unique()
            ->values()
            ->all();
    }
}
