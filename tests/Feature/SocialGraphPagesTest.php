<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Blocks;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Lists;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Relationships;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Suggestions;
use Liberu\SocialNetwork\SocialGraph\Models\Block;
use Liberu\SocialNetwork\SocialGraph\Models\GraphList;
use Liberu\SocialNetwork\SocialGraph\Models\Relationship;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Tester', 'onboarding_completed_at' => now()]);
    $this->me = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $this->user->getKey(), 'handle' => 'tester']);
    $this->laura = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create(['name' => 'Laura'])->getKey(), 'handle' => 'laura']);
    $this->marta = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create(['name' => 'Marta'])->getKey(), 'handle' => 'marta']);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function relation(Profile $source, Profile $target, string $type = 'follow', string $status = 'accepted'): Relationship
{
    return Relationship::query()->create([
        'id' => (string) Str::uuid(),
        'source_profile_id' => $source->id,
        'target_profile_id' => $target->id,
        'relationship_type' => $type,
        'status' => $status,
    ]);
}

it('renders the four social graph pages', function (string $route) {
    $this->get(route($route))->assertOk();
})->with([
    'filament.app.pages.relationships',
    'filament.app.pages.lists',
    'filament.app.pages.blocks',
    'filament.app.pages.suggestions',
]);

it('lists my follows, followers, friends and requests with what they mean', function () {
    relation($this->me, $this->laura);
    relation($this->marta, $this->me);
    relation($this->laura, $this->me, 'friend', 'pending');
    $others = relation($this->laura, $this->marta);

    Livewire::test(Relationships::class)
        ->assertSee(['You follow', 'Follows you', 'Wants to be friends', 'Laura', 'Marta'])
        ->assertCanNotSeeTableRecords([$others]);

    expect(Relationships::getNavigationBadge())->toBe('1');
});

it('follows someone and sends a friend request', function () {
    Livewire::test(Relationships::class)
        ->callAction('follow', ['profile' => $this->laura->id])
        ->assertHasNoActionErrors()
        ->callAction('befriend', ['profile' => $this->marta->id])
        ->assertHasNoActionErrors();

    expect(Relationship::query()->where(['source_profile_id' => $this->me->id, 'target_profile_id' => $this->laura->id, 'relationship_type' => 'follow'])->value('status'))->toBe('accepted')
        ->and(Relationship::query()->where(['source_profile_id' => $this->me->id, 'target_profile_id' => $this->marta->id, 'relationship_type' => 'friend'])->value('status'))->toBe('pending');
});

it('accepts and declines friend requests, and cancels my own', function () {
    $fromLaura = relation($this->laura, $this->me, 'friend', 'pending');
    $fromMarta = relation($this->marta, $this->me, 'friend', 'pending');
    $mine = relation($this->me, $this->laura, 'friend', 'pending');

    Livewire::test(Relationships::class)
        ->assertTableActionHidden('accept', $mine)
        ->callTableAction('accept', $fromLaura)
        ->callTableAction('reject', $fromMarta)
        ->callTableAction('cancelRequest', $mine);

    expect($fromLaura->fresh()->status)->toBe('accepted')
        ->and($fromMarta->fresh()->status)->toBe('rejected')
        ->and($mine->fresh()->status)->toBe('cancelled');
});

it('changes who can see one of my relationships', function () {
    $follow = relation($this->me, $this->laura);

    Livewire::test(Relationships::class)
        ->callTableAction('visibility', $follow, ['visibility' => 'private'])
        ->assertHasNoTableActionErrors();

    expect($follow->fresh()->visibility)->toBe('private');
});

it('blocks someone from the people page, removing what was between us', function () {
    $follow = relation($this->me, $this->laura);
    relation($this->laura, $this->me);

    Livewire::test(Relationships::class)->callTableAction('block', $follow);

    expect(Block::query()->where(['source_profile_id' => $this->me->id, 'target_profile_id' => $this->laura->id])->exists())->toBeTrue()
        ->and(Relationship::query()->count())->toBe(0);
});

it('creates a list with people, adds and removes members', function () {
    Livewire::test(Lists::class)
        ->callAction('create', ['name' => 'Senderismo', 'visibility' => 'private', 'profiles' => [$this->laura->id]])
        ->assertHasNoActionErrors();

    $list = GraphList::query()->where('name', 'Senderismo')->firstOrFail();

    Livewire::test(Lists::class)
        ->assertSee('Laura')
        ->callTableAction('addPeople', $list, ['profiles' => [$this->marta->id]])
        ->callTableAction('removePerson', $list, ['profile' => $this->laura->id]);

    expect($list->profiles()->pluck('social_profiles.id')->all())->toBe([$this->marta->id]);
});

it('adds someone to a list from the people page', function () {
    $list = GraphList::query()->create(['id' => (string) Str::uuid(), 'owner_profile_id' => $this->me->id, 'name' => 'Club', 'visibility' => 'private']);
    $follow = relation($this->me, $this->laura);

    Livewire::test(Relationships::class)->callTableAction('addToList', $follow, ['list' => $list->id]);

    expect($list->profiles()->pluck('social_profiles.id')->all())->toBe([$this->laura->id]);
});

it('blocks and unblocks from the blocks page', function () {
    Livewire::test(Blocks::class)
        ->callAction('block', ['profile' => $this->marta->id])
        ->assertHasNoActionErrors()
        ->assertSee('Marta');

    $block = Block::query()->where('target_profile_id', $this->marta->id)->firstOrFail();

    Livewire::test(Blocks::class)->callTableAction('unblock', $block);

    expect(Block::query()->count())->toBe(0);
});

it('suggests public profiles I am not connected to, and follows from there', function () {
    relation($this->me, $this->laura);

    Livewire::test(Suggestions::class)
        ->assertCanSeeTableRecords([$this->marta])
        ->assertCanNotSeeTableRecords([$this->laura, $this->me])
        ->callTableAction('follow', $this->marta)
        ->assertCanNotSeeTableRecords([$this->marta]);

    expect(Relationship::query()->where(['source_profile_id' => $this->me->id, 'target_profile_id' => $this->marta->id])->exists())->toBeTrue();
});

it('hides relationships with someone a block stands between', function () {
    $follow = relation($this->laura, $this->me);
    Block::query()->create(['id' => (string) Str::uuid(), 'source_profile_id' => $this->laura->id, 'target_profile_id' => $this->me->id]);

    Livewire::test(Relationships::class)->assertCanNotSeeTableRecords([$follow]);
});
