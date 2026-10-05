<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\SocialNetwork\Communities\CommunityDetails;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Edits a community's details with the same rules as creating one. Fields
 * left out keep their current value. Spots cannot drop below the members a
 * community already has.
 */
final readonly class UpdateCommunity
{
    private const array EDITABLE = ['name', 'description', 'visibility', 'rules', 'modality', 'city', 'latitude', 'longitude', 'max_members', 'meeting_day', 'meeting_time', 'timezone'];

    public function __construct(private CommunityAuthorizer $authorizer, private CommunityDetails $details) {}

    /** @param array<string,mixed> $attributes */
    public function handle(Profile $manager, Community $community, array $attributes): Community
    {
        $this->authorizer->manage($manager, $community);

        $current = $community->only(self::EDITABLE);
        $details = $this->details->validated([...$current, ...array_intersect_key($attributes, array_flip(self::EDITABLE))]);

        return DB::transaction(function () use ($community, $details): Community {
            $locked = Community::query()->whereKey($community->getKey())->lockForUpdate()->firstOrFail();
            $members = $locked->memberships()->where('status', 'active')->count();

            if ($details['max_members'] !== null && $details['max_members'] < $members) {
                throw new InvalidArgumentException("Spots cannot be fewer than the {$members} members it already has.");
            }

            $locked->update($details);

            return $locked->refresh();
        });
    }
}
