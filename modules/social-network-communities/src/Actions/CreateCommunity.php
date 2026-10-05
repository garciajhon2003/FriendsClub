<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\CommunityDetails;
use Liberu\SocialNetwork\Communities\Contracts\CommunityAuthorizer;
use Liberu\SocialNetwork\Communities\Events\CommunityCreated;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Profiles\Models\Profile;

final readonly class CreateCommunity
{
    public const int MIN_MEMBERS = CommunityDetails::MIN_MEMBERS;

    public const int MAX_MEMBERS = CommunityDetails::MAX_MEMBERS;

    public function __construct(
        private CommunityAuthorizer $authorizer,
        private Dispatcher $events,
        private CommunityDetails $details,
    ) {}

    /** @param array<string,mixed> $attributes */
    public function handle(Profile $owner, array $attributes): Community
    {
        $this->authorizer->create($owner);
        $details = $this->details->validated($attributes);

        $community = DB::transaction(function () use ($owner, $details): Community {
            $community = Community::query()->create([
                'id' => (string) Str::uuid(),
                'owner_profile_id' => $owner->getKey(),
                'slug' => Str::slug($details['name']).'-'.Str::lower(Str::random(6)),
                ...$details,
            ]);
            Membership::query()->create(['community_id' => $community->getKey(), 'profile_id' => $owner->getKey(), 'role' => 'owner', 'status' => 'active']);

            return $community;
        });
        $this->events->dispatch(new CommunityCreated($community));

        return $community;
    }
}
