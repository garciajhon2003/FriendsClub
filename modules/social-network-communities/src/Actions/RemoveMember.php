<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use InvalidArgumentException;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Takes a member out and keeps them out: the membership is marked "removed",
 * which JoinCommunity refuses. The owner cannot be removed.
 */
final readonly class RemoveMember
{
    public function __construct(private CommunityAuthorizer $authorizer) {}

    public function handle(Profile $manager, Community $community, Profile $member): Membership
    {
        $this->authorizer->manage($manager, $community);

        $membership = Membership::query()
            ->where(['community_id' => $community->getKey(), 'profile_id' => $member->getKey()])
            ->whereIn('status', ['pending', 'active'])
            ->first() ?? throw new InvalidArgumentException('This person is not a member.');

        if ($membership->role === 'owner') {
            throw new InvalidArgumentException('The owner cannot be removed.');
        }

        $membership->update(['status' => 'removed']);

        return $membership->refresh();
    }
}
