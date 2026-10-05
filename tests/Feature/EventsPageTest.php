<?php

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Events\Filament\Pages\Events;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Events\Models\EventUpdate;
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

function ownEvent(Profile $owner, array $attributes = []): Event
{
    return Event::query()->create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => $owner->getKey(),
        'state' => 'draft',
        'visibility' => 'public',
        'title' => 'Mi evento',
        'starts_at' => now()->addWeek(),
        ...$attributes,
    ]);
}

it('renders the events page', function () {
    $this->get(route('filament.app.pages.events'))->assertOk();
});

it('lists public upcoming events and my drafts, but not other people drafts', function () {
    $mine = ownEvent($this->profile);
    $othersDraft = ownEvent(Profile::query()->where('handle', 'laura')->first(), ['title' => 'Borrador ajeno']);

    Livewire::test(Events::class)
        ->assertCanSeeTableRecords(Event::query()->where('state', 'published')->get()->push($mine))
        ->assertCanNotSeeTableRecords([$othersDraft]);
});

it('creates and publishes an event through the module action', function () {
    $hobby = Hobby::where('slug', 'reposteria')->first();

    Livewire::test(Events::class)
        ->callAction('create', [
            'title' => 'Tarde de cupcakes',
            'hobby_id' => $hobby->id,
            'visibility' => 'public',
            'starts_at' => now()->addDays(3)->format('Y-m-d H:i'),
            'location_name' => 'Espai Creatiu, Barcelona',
            'capacity' => 8,
            'publish_now' => true,
        ])
        ->assertHasNoFormErrors();

    $event = Event::where('title', 'Tarde de cupcakes')->firstOrFail();

    expect($event->owner_profile_id)->toBe($this->profile->id)
        ->and($event->state)->toBe('published')
        ->and($event->hobby_id)->toBe($hobby->id)
        ->and($event->capacity)->toBe(8)
        ->and($event->location)->toBe(['name' => 'Espai Creatiu, Barcelona']);
});

it('lets the organizer publish, invite and post updates', function () {
    $event = ownEvent($this->profile);
    $laura = Profile::query()->where('handle', 'laura')->first();

    Livewire::test(Events::class)
        ->callTableAction('publish', $event)
        ->callTableAction('invite', $event, ['profiles' => [$laura->id]])
        ->callTableAction('postUpdate', $event, ['body' => 'Traed delantal'])
        ->assertHasNoTableActionErrors();

    expect($event->refresh()->state)->toBe('published')
        ->and(DB::table('social_event_invitations')->where(['event_id' => $event->id, 'profile_id' => $laura->id])->exists())->toBeTrue()
        ->and(EventUpdate::query()->where('event_id', $event->id)->value('body'))->toBe('Traed delantal');
});

it('hides the organizer tools on events I do not own', function () {
    $event = Event::query()->where('state', 'published')->first();

    Livewire::test(Events::class)
        ->assertTableActionHidden('publish', $event)
        ->assertTableActionHidden('cancel', $event);
});

it('records attendance and respects capacity', function () {
    $event = ownEvent(Profile::query()->where('handle', 'laura')->first(), ['state' => 'published', 'capacity' => 1]);

    Livewire::test(Events::class)
        ->callTableAction('attend_going', $event);

    expect(DB::table('social_event_attendance')->where(['event_id' => $event->id, 'profile_id' => $this->profile->id])->value('state'))
        ->toBe('going');

    $other = Profile::query()->where('handle', 'marta')->first();
    $this->actingAs(User::find($other->user_id));

    Livewire::test(Events::class)
        ->callTableAction('attend_going', $event)
        ->assertNotified('Event capacity has been reached.');

    expect(DB::table('social_event_attendance')->where('event_id', $event->id)->count())->toBe(1);
});

it('shows the event details with attendees and updates', function () {
    $event = ownEvent($this->profile, ['state' => 'published', 'location' => ['name' => 'Online']]);
    EventUpdate::query()->create(['event_id' => $event->id, 'author_profile_id' => $this->profile->id, 'body' => 'Enlace en el chat']);

    Livewire::test(Events::class)
        ->callTableAction('attend_going', $event)
        ->mountTableAction('view', $event)
        ->assertMountedActionModalSee(['Online', 'Enlace en el chat', $this->user->name]);
});

it('lets the organizer edit an event through the module action', function () {
    $event = ownEvent($this->profile);

    Livewire::test(Events::class)
        ->callTableAction('edit', $event, [
            'title' => 'Título nuevo',
            'capacity' => 20,
            'location_name' => 'Parc de Collserola',
            'hobby_id' => Hobby::where('slug', 'senderismo')->value('id'),
        ])
        ->assertHasNoTableActionErrors();

    expect($event->refresh())
        ->title->toBe('Título nuevo')
        ->capacity->toBe(20)
        ->location->toBe(['name' => 'Parc de Collserola'])
        ->hobby_id->toBe(Hobby::where('slug', 'senderismo')->value('id'));
});
