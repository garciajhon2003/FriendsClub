<?php

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Filament\Pages\Communities;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Livewire;

use function Pest\Laravel\seed;

beforeEach(function () {
    seed(DemoDataSeeder::class);

    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->user->getKey(),
        'handle' => 'tester',
    ]);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function membershipOf(Community $community, Profile $profile): ?object
{
    return DB::table('social_community_memberships')
        ->where(['community_id' => $community->id, 'profile_id' => $profile->id])
        ->first();
}

it('renders the communities page', function () {
    $this->get(route('filament.app.pages.communities'))->assertOk();
});

it('calls communities clubs, in English and in Spanish', function () {
    Livewire::test(Communities::class)
        ->assertSee('Clubs')
        ->assertSee('New club')
        ->assertDontSee('New community');

    app()->setLocale('es');

    Livewire::test(Communities::class)->assertSee('Nuevo club');
});

it('lists public communities and my own, but not other people private ones', function () {
    $hidden = Community::query()->create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => Profile::query()->where('handle', 'laura')->value('id'),
        'name' => 'Club secreto',
        'slug' => 'club-secreto',
        'visibility' => 'private',
    ]);

    Livewire::test(Communities::class)
        ->assertCanSeeTableRecords(Community::query()->where('visibility', 'public')->get())
        ->assertCanNotSeeTableRecords([$hidden]);
});

it('creates a club through the module action with the FriendsClub fields', function () {
    $hobby = Hobby::where('slug', 'cocina')->first();

    Livewire::test(Communities::class)
        ->callAction('create', [
            'name' => 'Cocina de Domingo',
            'description' => 'Recetas fáciles',
            'visibility' => 'public',
            'rules' => ['Traer ingredientes'],
            'hobby_id' => $hobby->id,
            'modality' => 'in_person',
            'city' => 'Madrid',
            'max_members' => 10,
            'meeting_day' => 7,
            'meeting_time' => '12:00',
        ])
        ->assertHasNoFormErrors();

    $club = Community::where('name', 'Cocina de Domingo')->firstOrFail();

    expect($club->owner_profile_id)->toBe($this->profile->id)
        ->and($club->rules)->toBe(['Traer ingredientes'])
        ->and($club->hobby_id)->toBe($hobby->id)
        ->and($club->modality)->toBe('in_person')
        ->and($club->city)->toBe('Madrid')
        ->and((int) $club->max_members)->toBe(10)
        ->and((int) $club->meeting_day)->toBe(7)
        ->and(membershipOf($club, $this->profile))->role->toBe('owner')->status->toBe('active');
});

it('lets a member join and leave a public club', function () {
    $club = Community::where('slug', 'senderismo-juntos')->firstOrFail();

    Livewire::test(Communities::class)
        ->callTableAction('join', $club)
        ->assertNotified('Welcome to the club!');

    expect(membershipOf($club, $this->profile)->status)->toBe('active');

    Livewire::test(Communities::class)
        ->assertTableActionHidden('join', $club)
        ->callTableAction('leave', $club);

    expect(membershipOf($club, $this->profile))->toBeNull();
});

it('refuses to join a full club', function () {
    $club = Community::where('slug', 'miniaturas-y-dioramas')->firstOrFail();
    $club->forceFill(['max_members' => 1])->save();

    Livewire::test(Communities::class)
        ->callTableAction('join', $club)
        ->assertNotified('This club is full.');

    expect(membershipOf($club, $this->profile))->toBeNull();
});

it('does not let the owner leave their own club', function () {
    $club = Community::where('slug', 'amantes-de-la-reposteria')->firstOrFail();
    $this->actingAs(User::find(Profile::query()->where('handle', 'laura')->value('user_id')));

    Livewire::test(Communities::class)
        ->assertTableActionHidden('leave', $club)
        ->assertTableActionHidden('join', $club);
});

it('shows the club details with members and rules', function () {
    $club = Community::where('slug', 'amantes-de-la-reposteria')->firstOrFail();
    $club->forceFill(['rules' => ['Compartir recetas']])->save();

    Livewire::test(Communities::class)
        ->mountTableAction('view', $club)
        ->assertMountedActionModalSee(['Compartir recetas', 'Laura', 'Marta', 'Repostería', 'Sunday 17:00']);
});
