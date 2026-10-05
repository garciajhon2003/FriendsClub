<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Discovery\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Liberu\SocialNetwork\Discovery\Events\DiscoveryIndexUpdated;
use Liberu\SocialNetwork\Discovery\Models\DiscoveryIndex;

/**
 * Adds or refreshes one resource in the search index: the system side of
 * IndexResource. IndexResource is gated on the signed-in user owning the
 * resource, which a scheduled publication (nobody signed in) cannot satisfy,
 * so the caller vouches for the owner instead. The validation is the same.
 */
final readonly class SyncIndexEntry
{
    /** Words shorter than this are left out of the terms. */
    private const int MIN_TERM_LENGTH = 4;

    public function __construct(private Dispatcher $events) {}

    /**
     * @param  array{resource_type: string, resource_id: string, owner_profile_id: ?string, body: string, visibility?: string, engagement_score?: int, published_at?: mixed}  $attributes
     */
    public function handle(array $attributes): DiscoveryIndex
    {
        $type = trim($attributes['resource_type']);
        $id = $attributes['resource_id'];
        $body = trim($attributes['body']);
        $visibility = $attributes['visibility'] ?? 'public';

        if ($type === '' || ! Str::isUuid($id) || $body === ''
            || mb_strlen($body) > (int) config('social-network-discovery.max_text_length')
            || ! in_array($visibility, (array) config('social-network-discovery.visibility_states'), true)) {
            throw new InvalidArgumentException('The discovery index payload is invalid.');
        }

        $index = DB::transaction(fn (): DiscoveryIndex => DiscoveryIndex::query()->updateOrCreate(
            ['resource_type' => $type, 'resource_id' => $id],
            [
                'id' => DiscoveryIndex::query()->where(['resource_type' => $type, 'resource_id' => $id])->value('id') ?? (string) Str::uuid(),
                'owner_profile_id' => $attributes['owner_profile_id'],
                'visibility' => $visibility,
                'body' => $body,
                'terms' => $this->termsOf($body),
                'engagement_score' => max(0, (int) ($attributes['engagement_score'] ?? 0)),
                'published_at' => $attributes['published_at'] ?? now(),
            ],
        ));
        $this->events->dispatch(new DiscoveryIndexUpdated($index));

        return $index;
    }

    /** @return list<string> the distinct lowercase words, for trends */
    private function termsOf(string $body): array
    {
        return array_values(array_unique(
            str($body)->lower()->matchAll('/[\p{L}\p{N}]{'.self::MIN_TERM_LENGTH.',}/u')->all(),
        ));
    }
}
