<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use InvalidArgumentException;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Declines a pending request. The request is dropped rather than marked, so
 * the person may ask again later; to keep someone out, use RemoveMember.
 */
final readonly class RejectMembership
{
    public function __construct(private CommunityAuthorizer $authorizer) {}

    public function handle(Profile $manager, Community $community, Profile $member): void
    {
        $this->authorizer->manage($manager, $community);

        $deleted = Membership::query()
            ->where(['community_id' => $community->getKey(), 'profile_id' => $member->getKey(), 'status' => 'pending'])
            ->delete();

        if ($deleted === 0) {
            throw new InvalidArgumentException('There is no pending request from this person.');
        }
    }
}
