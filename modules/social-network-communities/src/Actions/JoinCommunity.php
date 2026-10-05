<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Profiles\Models\Profile;

final readonly class JoinCommunity
{
    public function __construct(private CommunityAuthorizer $authorizer) {}

    /**
     * An existing membership is returned untouched, so joining again never demotes
     * an owner or moderator, and a removed member cannot rejoin. A community with
     * no free spot refuses new members; the community row is locked so two members
     * cannot take its last spot at once.
     */
    public function handle(Profile $member, Community $community): Membership
    {
        $this->authorizer->join($member);

        return DB::transaction(function () use ($member, $community): Membership {
            $existing = Membership::query()->where(['community_id' => $community->getKey(), 'profile_id' => $member->getKey()])->lockForUpdate()->first();
            if ($existing?->status === 'removed') {
                throw new InvalidArgumentException('You were removed from this community.');
            }
            if ($existing === null && Community::query()->whereKey($community->getKey())->lockForUpdate()->firstOrFail()->isFull()) {
                throw new InvalidArgumentException('This community is full.');
            }

            return $existing ?? Membership::query()->create(['community_id' => $community->getKey(), 'profile_id' => $member->getKey(), 'role' => 'member', 'status' => $community->visibility === 'public' ? 'active' : 'pending']);
        });
    }
}
