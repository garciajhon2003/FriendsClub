<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Contracts;

use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

interface CommunityAuthorizer
{
    public function create(Profile $owner): void;

    public function join(Profile $member): void;

    public function leave(Profile $member): void;

    /** Editing the community and deciding who is in it. */
    public function manage(Profile $manager, Community $community): void;
}
