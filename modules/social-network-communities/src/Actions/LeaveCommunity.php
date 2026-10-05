<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Illuminate\Support\Facades\DB;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

final readonly class LeaveCommunity
{
    public function __construct(private CommunityAuthorizer $authorizer) {}

    public function handle(Profile $member, Community $community): void
    {
        $this->authorizer->leave($member);

        DB::table('social_community_memberships')->where(['community_id' => $community->getKey(), 'profile_id' => $member->getKey()])->where('role', '!=', 'owner')->where('status', '!=', 'removed')->delete();
    }
}
