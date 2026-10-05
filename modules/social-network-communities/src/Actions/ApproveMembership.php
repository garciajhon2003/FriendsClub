<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Lets a pending member in. Like joining, it respects the community's spots,
 * with the community row locked so two approvals cannot take the last one.
 */
final readonly class ApproveMembership
{
    public function __construct(private CommunityAuthorizer $authorizer) {}

    public function handle(Profile $manager, Community $community, Profile $member): Membership
    {
        $this->authorizer->manage($manager, $community);

        return DB::transaction(function () use ($community, $member): Membership {
            $locked = Community::query()->whereKey($community->getKey())->lockForUpdate()->firstOrFail();
            $request = Membership::query()
                ->where(['community_id' => $locked->getKey(), 'profile_id' => $member->getKey(), 'status' => 'pending'])
                ->first() ?? throw new InvalidArgumentException('There is no pending request from this person.');

            if ($locked->isFull()) {
                throw new InvalidArgumentException('This community is full.');
            }

            $request->update(['status' => 'active']);

            return $request->refresh();
        });
    }
}
