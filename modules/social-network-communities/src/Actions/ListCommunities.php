<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Illuminate\Database\Eloquent\Collection;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Every community, private ones included: a private community is one you join
 * on request (see JoinCommunity), so people have to be able to find it. What
 * stays private is its inside (members, rules), which Community::visibleTo()
 * guards.
 */
final class ListCommunities
{
    public function handle(Profile $viewer, int $limit = 25): Collection
    {
        return Community::query()->latest()->limit(max(1, min($limit, 100)))->get();
    }
}
