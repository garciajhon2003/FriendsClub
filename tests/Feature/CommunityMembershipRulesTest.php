<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Actions\CreateCommunity;
use Liberu\SocialNetwork\Communities\Actions\JoinCommunity;
use Liberu\SocialNetwork\Communities\Actions\LeaveCommunity;
use Liberu\SocialNetwork\Communities\Actions\ListCommunities;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Profiles\Models\Profile;

function communityProfile(string $handle): Profile
{
    return Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => User::factory()->create()->getKey(),
        'handle' => $handle,
    ]);
}

function actAs(Profile $profile): void
{
    test()->actingAs(User::find($profile->user_id));
}

function membershipRow(Community $community, Profile $profile): ?object
{
    return DB::table('social_community_memberships')
        ->where(['community_id' => $community->getKey(), 'profile_id' => $profile->getKey()])
        ->first();
}

beforeEach(function () {
    $this->owner = communityProfile('owner');
    $this->member = communityProfile('member');

    actAs($this->owner);
    $this->public = app(CreateCommunity::class)->handle($this->owner, ['name' => 'Public club']);
    $this->private = app(CreateCommunity::class)->handle($this->owner, ['name' => 'Private club', 'visibility' => 'private']);
});

it('keeps the owner role when the owner joins their own community', function () {
    app(JoinCommunity::class)->handle($this->owner, $this->public);

    expect(membershipRow($this->public, $this->owner))->role->toBe('owner')->status->toBe('active');
});

it('does not let a removed member rejoin', function () {
    DB::table('social_community_memberships')->insert([
        'community_id' => $this->public->getKey(),
        'profile_id' => $this->member->getKey(),
        'role' => 'member',
        'status' => 'removed',
    ]);
    actAs($this->member);

    expect(fn () => app(JoinCommunity::class)->handle($this->member, $this->public))
        ->toThrow(InvalidArgumentException::class, 'You were removed from this community.');
    expect(membershipRow($this->public, $this->member)->status)->toBe('removed');

    app(LeaveCommunity::class)->handle($this->member, $this->public);
    expect(membershipRow($this->public, $this->member))->not->toBeNull();
});

it('does not let someone remove another profile from a community', function () {
    actAs($this->member);
    app(JoinCommunity::class)->handle($this->member, $this->public);

    $intruder = communityProfile('intruder');
    actAs($intruder);

    expect(fn () => app(LeaveCommunity::class)->handle($this->member, $this->public))
        ->toThrow(AuthorizationException::class);
    expect(membershipRow($this->public, $this->member))->not->toBeNull();
});

it('lists private communities the viewer is an active member of', function () {
    actAs($this->member);
    app(JoinCommunity::class)->handle($this->member, $this->private);

    expect(app(ListCommunities::class)->handle($this->member)->modelKeys())
        ->not->toContain($this->private->getKey());

    DB::table('social_community_memberships')
        ->where(['community_id' => $this->private->getKey(), 'profile_id' => $this->member->getKey()])
        ->update(['status' => 'active']);

    expect(app(ListCommunities::class)->handle($this->member)->modelKeys())
        ->toContain($this->private->getKey(), $this->public->getKey());
});

it('does not list soft deleted communities to members', function () {
    actAs($this->member);
    app(JoinCommunity::class)->handle($this->member, $this->public);
    $this->public->delete();

    expect(app(ListCommunities::class)->handle($this->member)->modelKeys())
        ->not->toContain($this->public->getKey());
});
