<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Actions\ApproveMembership;
use Liberu\SocialNetwork\Communities\Actions\CreateCommunity;
use Liberu\SocialNetwork\Communities\Actions\JoinCommunity;
use Liberu\SocialNetwork\Communities\Actions\UpdateCommunity;
use Liberu\SocialNetwork\Communities\Filament\Pages\Communities;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Livewire;

beforeEach(function () {
    $person = function (string $name): array {
        $user = User::factory()->create(['name' => $name, 'onboarding_completed_at' => now()]);

        return [$user, Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'handle' => Str::slug($name)])];
    };
    [$this->ownerUser, $this->owner] = $person('Laura');
    [$this->askerUser, $this->asker] = $person('Marta');
    [$this->otherUser, $this->other] = $person('Javier');

    $this->actingAs($this->ownerUser);
    $this->club = app(CreateCommunity::class)->handle($this->owner, [
        'name' => 'Repostería secreta',
        'description' => 'Recetas de la abuela',
        'visibility' => 'private',
        'rules' => ['No compartir recetas fuera'],
        'max_members' => 3,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function membershipStatus(Community $club, Profile $profile): ?string
{
    return DB::table('social_community_memberships')
        ->where(['community_id' => $club->id, 'profile_id' => $profile->id])
        ->value('status');
}

function askToJoin(object $test, Profile $profile, User $user): void
{
    $test->actingAs($user);
    app(JoinCommunity::class)->handle($profile, $test->club);
}

it('shows private clubs to everyone so they can ask to join, but keeps members and rules inside', function () {
    $this->actingAs($this->askerUser);

    Livewire::test(Communities::class)
        ->assertCanSeeTableRecords([$this->club])
        ->assertTableActionVisible('join', $this->club)
        ->mountTableAction('view', $this->club)
        ->assertMountedActionModalSee('Recetas de la abuela')
        ->assertMountedActionModalDontSee(['No compartir recetas fuera', 'Laura, ']);
});

it('sends a join request to a private club and shows it to the owner with a badge', function () {
    $this->actingAs($this->askerUser);
    Livewire::test(Communities::class)
        ->callTableAction('join', $this->club)
        ->assertNotified('Request sent to the owner');

    expect(membershipStatus($this->club, $this->asker))->toBe('pending');

    $this->actingAs($this->ownerUser);
    expect(Communities::getNavigationBadge())->toBe('1');
});

it('lets the owner approve and decline requests', function () {
    askToJoin($this, $this->asker, $this->askerUser);
    askToJoin($this, $this->other, $this->otherUser);

    $this->actingAs($this->ownerUser);

    Livewire::test(Communities::class)
        ->callTableAction('approveRequests', $this->club, ['profiles' => [$this->asker->id]])
        ->assertHasNoTableActionErrors()
        ->callTableAction('declineRequests', $this->club, ['profiles' => [$this->other->id]])
        ->assertHasNoTableActionErrors();

    expect(membershipStatus($this->club, $this->asker))->toBe('active')
        ->and(membershipStatus($this->club, $this->other))->toBeNull()
        ->and(Communities::getNavigationBadge())->toBeNull();
});

it('lets an approved member see the inside of the private club', function () {
    askToJoin($this, $this->asker, $this->askerUser);
    $this->actingAs($this->ownerUser);
    app(ApproveMembership::class)->handle($this->owner, $this->club, $this->asker);

    $this->actingAs($this->askerUser);

    Livewire::test(Communities::class)
        ->mountTableAction('view', $this->club)
        ->assertMountedActionModalSee(['No compartir recetas fuera', 'Laura', 'Marta']);
});

it('does not approve beyond the spots', function () {
    $this->club->forceFill(['max_members' => 2])->save();
    askToJoin($this, $this->asker, $this->askerUser);
    askToJoin($this, $this->other, $this->otherUser);

    $this->actingAs($this->ownerUser);

    Livewire::test(Communities::class)
        ->callTableAction('approveRequests', $this->club, ['profiles' => [$this->asker->id, $this->other->id]])
        ->assertNotified('This club is full.');

    // Laura and Marta fill the two spots; Javier stays waiting.
    expect(membershipStatus($this->club, $this->asker))->toBe('active')
        ->and(membershipStatus($this->club, $this->other))->toBe('pending');
});

it('removes a member, who cannot join again', function () {
    askToJoin($this, $this->asker, $this->askerUser);
    $this->actingAs($this->ownerUser);
    app(ApproveMembership::class)->handle($this->owner, $this->club, $this->asker);

    Livewire::test(Communities::class)
        ->callTableAction('removeMember', $this->club, ['profile' => $this->asker->id])
        ->assertHasNoTableActionErrors();

    expect(membershipStatus($this->club, $this->asker))->toBe('removed');

    $this->actingAs($this->askerUser);
    Livewire::test(Communities::class)
        ->callTableAction('join', $this->club)
        ->assertNotified('You were removed from this club.');
});

it('lets the owner edit the club, hobby included', function () {
    $hobby = Hobby::query()->create(['name' => 'Repostería', 'slug' => 'reposteria', 'is_active' => true]);

    Livewire::test(Communities::class)
        ->callTableAction('edit', $this->club, [
            'name' => 'Repostería abierta',
            'visibility' => 'public',
            'modality' => 'in_person',
            'city' => 'Barcelona',
            'max_members' => 10,
            'meeting_day' => 6,
            'hobby_id' => $hobby->id,
        ])
        ->assertHasNoTableActionErrors();

    expect($this->club->fresh())
        ->name->toBe('Repostería abierta')
        ->visibility->toBe('public')
        ->city->toBe('Barcelona')
        ->max_members->toBe(10)
        ->meeting_day->toBe(6)
        ->hobby_id->toBe($hobby->id);
});

it('does not let spots drop below the members a club already has', function () {
    askToJoin($this, $this->asker, $this->askerUser);
    $this->actingAs($this->ownerUser);
    app(ApproveMembership::class)->handle($this->owner, $this->club, $this->asker);

    expect(fn () => app(UpdateCommunity::class)->handle($this->owner, $this->club, ['max_members' => 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('hides the manage tools from non-owners and refuses their edits', function () {
    $this->actingAs($this->otherUser);

    Livewire::test(Communities::class)->assertTableActionHidden('edit', $this->club);

    expect(fn () => app(UpdateCommunity::class)->handle($this->other, $this->club, ['name' => 'Robado']))
        ->toThrow(AuthorizationException::class);
});
