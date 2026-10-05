<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Actions\PublishScheduledPublications;
use Liberu\SocialNetwork\Publishing\Filament\Pages\Publications;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->user->getKey(),
        'handle' => 'tester',
    ]);
    $this->friend = Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => User::factory()->create(['name' => 'Laura'])->getKey(),
        'handle' => 'laura',
    ]);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function draftOf(Profile $author, array $attributes = []): Publication
{
    return Publication::query()->create([
        'id' => (string) Str::uuid(),
        'author_profile_id' => $author->id,
        'kind' => 'post',
        'state' => 'draft',
        'audience' => 'public',
        'body' => 'Borrador de prueba',
        ...$attributes,
    ]);
}

it('renders the publications page', function () {
    $this->get(route('filament.app.pages.publications'))->assertOk()->assertSee('My publications');
});

it('lists only my publications', function () {
    $mine = draftOf($this->profile);
    $theirs = draftOf($this->friend, ['state' => 'published', 'published_at' => now()]);

    Livewire::test(Publications::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('publishes a post right away with mentions, hashtags, links and a poll', function () {
    Livewire::test(Publications::class)
        ->callAction('create', [
            'kind' => 'post',
            'audience' => 'followers',
            'body' => 'Domingo de ruta',
            'mentions' => [$this->friend->id],
            'hashtags' => ['#Senderismo', 'collserola'],
            'links' => [['url' => 'https://example.com/ruta', 'title' => 'Mapa']],
            'poll_options' => ['Mañana', 'Tarde'],
            'timing' => 'now',
        ])
        ->assertHasNoFormErrors();

    $post = Publication::query()->where('body', 'Domingo de ruta')->firstOrFail();

    expect($post->state)->toBe('published')
        ->and($post->published_at)->not->toBeNull()
        ->and($post->audience)->toBe('followers')
        ->and(DB::table('social_publication_mentions')->where('publication_id', $post->id)->pluck('profile_id')->all())->toBe([$this->friend->id])
        ->and(DB::table('social_publication_hashtags')->where('publication_id', $post->id)->orderBy('id')->pluck('tag')->all())->toBe(['senderismo', 'collserola'])
        ->and(DB::table('social_publication_links')->where('publication_id', $post->id)->value('url'))->toBe('https://example.com/ruta')
        ->and(json_decode(DB::table('social_publication_polls')->where('publication_id', $post->id)->value('options'), true))->toBe(['Mañana', 'Tarde']);
});

it('saves a draft and schedules another one, which the scheduler then publishes', function () {
    $page = Livewire::test(Publications::class)
        ->callAction('create', ['kind' => 'post', 'audience' => 'public', 'body' => 'Para más tarde', 'timing' => 'draft'])
        ->assertHasNoFormErrors()
        ->callAction('create', [
            'kind' => 'article',
            'audience' => 'public',
            'title' => 'Guía de rutas',
            'body' => 'Texto largo',
            'timing' => 'schedule',
            'scheduled_at' => now()->addHour()->format('Y-m-d H:i'),
        ])
        ->assertHasNoFormErrors();

    $draft = Publication::query()->where('body', 'Para más tarde')->firstOrFail();
    $scheduled = Publication::query()->where('title', 'Guía de rutas')->firstOrFail();

    expect($draft->state)->toBe('draft')->and($draft->scheduled_at)->toBeNull()
        ->and($scheduled->state)->toBe('draft')->and($scheduled->scheduled_at)->not->toBeNull();

    $page->assertSee('Scheduled');

    app(PublishScheduledPublications::class)->handle(now()->addHours(2));

    expect($scheduled->fresh()->state)->toBe('published')
        ->and($draft->fresh()->state)->toBe('draft');
});

it('requires a title for articles', function () {
    Livewire::test(Publications::class)
        ->callAction('create', ['kind' => 'article', 'audience' => 'public', 'body' => 'Sin título', 'timing' => 'draft'])
        ->assertHasFormErrors(['title' => 'required']);
});

it('edits a draft and keeps its edit history', function () {
    $draft = draftOf($this->profile);

    Livewire::test(Publications::class)
        ->callTableAction('edit', $draft, ['kind' => 'post', 'audience' => 'private', 'body' => 'Texto corregido', 'hashtags' => ['nuevo']])
        ->assertHasNoTableActionErrors();

    expect($draft->fresh())->body->toBe('Texto corregido')->audience->toBe('private')
        ->and(DB::table('social_publication_edits')->where('publication_id', $draft->id)->count())->toBe(1)
        ->and(DB::table('social_publication_hashtags')->where('publication_id', $draft->id)->pluck('tag')->all())->toBe(['nuevo']);
});

it('does not offer editing once published', function () {
    $published = draftOf($this->profile, ['state' => 'published', 'published_at' => now()]);

    Livewire::test(Publications::class)
        ->assertTableActionHidden('edit', $published)
        ->assertTableActionHidden('publishNow', $published)
        ->assertTableActionVisible('delete', $published);
});

it('publishes, schedules, unschedules and deletes a draft', function () {
    $draft = draftOf($this->profile);
    $other = draftOf($this->profile, ['body' => 'Otro']);

    Livewire::test(Publications::class)
        ->callTableAction('schedule', $draft, ['scheduled_at' => now()->addDay()->format('Y-m-d H:i')])
        ->callTableAction('unschedule', $draft)
        ->callTableAction('publishNow', $draft)
        ->callTableAction('delete', $other);

    expect($draft->fresh())->state->toBe('published')->scheduled_at->toBeNull()
        ->and(Publication::query()->find($other->id))->toBeNull();
});

it('shows the extras in the details', function () {
    $draft = draftOf($this->profile, ['title' => 'Con extras']);
    DB::table('social_publication_hashtags')->insert(['publication_id' => $draft->id, 'tag' => 'fotografia', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('social_publication_mentions')->insert(['publication_id' => $draft->id, 'profile_id' => $this->friend->id, 'created_at' => now(), 'updated_at' => now()]);

    Livewire::test(Publications::class)
        ->mountTableAction('view', $draft)
        ->assertMountedActionModalSee(['Con extras', '#fotografia', 'Laura', 'Never edited']);
});
