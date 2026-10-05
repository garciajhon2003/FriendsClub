<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Engagement\Models\Engagement;
use Liberu\SocialNetwork\Feed\Filament\Pages\Entries;
use Liberu\SocialNetwork\Feed\Models\FeedEntry;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Tester', 'onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $this->user->getKey(), 'handle' => 'tester']);
    $this->laura = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create(['name' => 'Laura'])->getKey(), 'handle' => 'laura']);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function feedPost(Profile $author, string $body, array $attributes = []): Publication
{
    return Publication::query()->create([
        'id' => (string) Str::uuid(),
        'author_profile_id' => $author->id,
        'kind' => 'post',
        'state' => 'published',
        'audience' => 'public',
        'body' => $body,
        'published_at' => now()->subHour(),
        ...$attributes,
    ]);
}

function inFeedOf(Profile $viewer, Publication $publication, float $rank = 0): FeedEntry
{
    return FeedEntry::query()->create([
        'id' => (string) Str::uuid(),
        'viewer_profile_id' => $viewer->id,
        'item_type' => $publication->getMorphClass(),
        'item_id' => $publication->id,
        'rank' => $rank,
        'visible_at' => now()->subMinute(),
    ]);
}

it('renders the feed page', function () {
    $this->get(route('filament.app.pages.entries'))->assertOk()->assertSee('Your feed is empty');
});

it('shows my feed as post cards, and not other people feeds', function () {
    inFeedOf($this->profile, feedPost($this->laura, 'Tarta de limón para el club'));
    inFeedOf($this->laura, feedPost($this->laura, 'Solo en el feed de Laura'));

    Livewire::test(Entries::class)
        ->assertSee(['Laura', 'Tarta de limón para el club'])
        ->assertDontSee('Solo en el feed de Laura');
});

it('skips posts I am not allowed to see', function () {
    inFeedOf($this->profile, feedPost($this->laura, 'Solo para seguidores', ['audience' => 'followers']));
    inFeedOf($this->profile, feedPost($this->laura, 'Todavía en borrador', ['state' => 'draft']));

    Livewire::test(Entries::class)
        ->assertDontSee(['Solo para seguidores', 'Todavía en borrador'])
        ->assertSee('Your feed is empty');
});

it('reacts to a post and takes the reaction back', function () {
    $post = feedPost($this->laura, 'Foto del atardecer');
    $entry = inFeedOf($this->profile, $post);

    $page = Livewire::test(Entries::class)->call('toggleReaction', $entry->id, 'love');

    expect(Engagement::query()->where(['target_id' => $post->id, 'actor_profile_id' => $this->profile->id, 'reaction_type' => 'love'])->exists())->toBeTrue();

    $page->call('toggleReaction', $entry->id, 'love');

    expect(Engagement::query()->where('target_id', $post->id)->exists())->toBeFalse();
});

it('comments on a post and shows the comment under it', function () {
    $post = feedPost($this->laura, 'Ruta del domingo');
    $entry = inFeedOf($this->profile, $post);

    Livewire::test(Entries::class)
        ->callAction(TestAction::make('comment')->arguments(['entry' => $entry->id]), ['body' => '¡Me apunto!'])
        ->assertHasNoActionErrors()
        ->assertSee(['Tester', '¡Me apunto!']);

    expect(Engagement::query()->where(['target_id' => $post->id, 'kind' => 'comment'])->value('body'))->toBe('¡Me apunto!');
});

it('hides an entry and can show hidden entries again', function () {
    $entry = inFeedOf($this->profile, feedPost($this->laura, 'Algo que no me interesa'));

    Livewire::test(Entries::class)
        ->call('hideEntry', $entry->id)
        ->assertDontSee('Algo que no me interesa')
        ->callAction('feedSettings', ['mode' => 'ranked', 'filters' => [], 'unhide' => true])
        ->assertSee('Algo que no me interesa');
});

it('switches the feed to most recent first', function () {
    $older = inFeedOf($this->profile, feedPost($this->laura, 'Primero por relevancia'), rank: 10);
    $newer = inFeedOf($this->profile, feedPost($this->laura, 'Primero por fecha'), rank: 1);
    $newer->forceFill(['created_at' => now()->addMinute()])->save();

    Livewire::test(Entries::class)
        ->assertSeeInOrder(['Primero por relevancia', 'Primero por fecha'])
        ->callAction('feedSettings', ['mode' => 'chronological', 'filters' => []])
        ->assertSeeInOrder(['Primero por fecha', 'Primero por relevancia']);

    expect(DB::table('social_feed_controls')->where('profile_id', $this->profile->id)->value('mode'))->toBe('chronological');
});

it('loads more entries on demand', function () {
    foreach (range(1, 21) as $i) {
        inFeedOf($this->profile, feedPost($this->laura, "Publicación número {$i}"), rank: 100 - $i);
    }

    Livewire::test(Entries::class)
        ->assertDontSee('Publicación número 21')
        ->call('loadMore')
        ->assertSee('Publicación número 21');
});
