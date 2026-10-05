<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Discovery\Filament\Pages\Search;
use Liberu\SocialNetwork\Discovery\Models\DiscoveryIndex;
use Liberu\SocialNetwork\Feed\Filament\Pages\Entries;
use Liberu\SocialNetwork\Feed\Models\FeedEntry;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Actions\CreatePublication;
use Liberu\SocialNetwork\Publishing\Actions\DeletePublication;
use Liberu\SocialNetwork\Publishing\Actions\PublishPublication;
use Liberu\SocialNetwork\Publishing\Actions\PublishScheduledPublications;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Liberu\SocialNetwork\SocialGraph\Models\Block;
use Liberu\SocialNetwork\SocialGraph\Models\Relationship;
use Livewire\Livewire;

beforeEach(function () {
    $person = function (string $name): array {
        $user = User::factory()->create(['name' => $name, 'onboarding_completed_at' => now()]);
        $profile = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'handle' => Str::slug($name)]);

        return [$user, $profile];
    };

    [$this->authorUser, $this->author] = $person('Laura');
    [$this->followerUser, $this->follower] = $person('Marta');
    [$this->friendUser, $this->friend] = $person('Javier');
    [$this->strangerUser, $this->stranger] = $person('Carlos');
    [$this->blockedUser, $this->blocked] = $person('Pablo');

    $relate = fn (Profile $source, Profile $target, string $type) => Relationship::query()->create([
        'id' => (string) Str::uuid(),
        'source_profile_id' => $source->id,
        'target_profile_id' => $target->id,
        'relationship_type' => $type,
        'status' => 'accepted',
    ]);
    $relate($this->follower, $this->author, 'follow');
    $relate($this->author, $this->friend, 'friend');
    $relate($this->blocked, $this->author, 'follow');
    Block::query()->create(['id' => (string) Str::uuid(), 'source_profile_id' => $this->author->id, 'target_profile_id' => $this->blocked->id]);

    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/** Laura writes and publishes a post as herself. */
function publishAsAuthor(object $test, string $body, string $audience = 'public'): Publication
{
    $test->actingAs($test->authorUser);
    $draft = app(CreatePublication::class)->handle($test->author, ['kind' => 'post', 'audience' => $audience, 'body' => $body]);

    return app(PublishPublication::class)->handle($test->author, $draft);
}

/** @return list<string> */
function feedOwnersOf(Publication $publication): array
{
    return FeedEntry::query()->where('item_id', $publication->id)->pluck('viewer_profile_id')->sort()->values()->all();
}

it('puts a public post in the feeds of the author, followers and friends, but not of strangers or blocked people', function () {
    $post = publishAsAuthor($this, 'Ruta por Collserola el domingo');

    expect(feedOwnersOf($post))->toBe(collect([$this->author->id, $this->follower->id, $this->friend->id])->sort()->values()->all());
});

it('keeps a private post in the author\'s own feed only', function () {
    $post = publishAsAuthor($this, 'Nota para mí', 'private');

    expect(feedOwnersOf($post))->toBe([$this->author->id]);
});

it('shows the new post on the follower\'s home page', function () {
    publishAsAuthor($this, 'Tarta de limón recién hecha');

    $this->actingAs($this->followerUser);

    Livewire::test(Entries::class)->assertSee(['Laura', 'Tarta de limón recién hecha']);
});

it('shows a followers-only post to followers and friends, and nobody else can open it', function () {
    publishAsAuthor($this, 'Solo para quien me sigue', 'followers');

    $this->actingAs($this->followerUser);
    Livewire::test(Entries::class)->assertSee('Solo para quien me sigue');

    $this->actingAs($this->friendUser);
    Livewire::test(Entries::class)->assertSee('Solo para quien me sigue');

    $this->actingAs($this->strangerUser);
    Livewire::test(Entries::class)->assertDontSee('Solo para quien me sigue');
});

it('makes a public post searchable, with its words as trends', function () {
    $post = publishAsAuthor($this, 'Senderismo nocturno con linternas');

    $entry = DiscoveryIndex::query()->where('resource_id', $post->id)->firstOrFail();

    expect($entry->visibility)->toBe('public')
        ->and($entry->owner_profile_id)->toBe($this->author->id)
        ->and($entry->terms)->toContain('senderismo', 'linternas');

    $this->actingAs($this->strangerUser);

    Livewire::test(Search::class)
        ->searchTable('linternas')
        ->assertSee('Senderismo nocturno con linternas');
});

it('also reaches feeds and search when a scheduled post goes out with nobody signed in', function () {
    $this->actingAs($this->authorUser);
    $draft = app(CreatePublication::class)->handle($this->author, ['kind' => 'post', 'audience' => 'public', 'body' => 'Publicación programada']);
    $draft->forceFill(['scheduled_at' => now()->subMinute()])->save();

    auth()->logout();
    app(PublishScheduledPublications::class)->handle();

    expect(feedOwnersOf($draft))->toContain($this->follower->id)
        ->and(DiscoveryIndex::query()->where('resource_id', $draft->id)->exists())->toBeTrue();
});

it('takes a deleted post out of every feed and out of search', function () {
    $post = publishAsAuthor($this, 'Me arrepentí de esto');

    app(DeletePublication::class)->handle($this->author, $post);

    expect(FeedEntry::query()->where('item_id', $post->id)->exists())->toBeFalse()
        ->and(DiscoveryIndex::query()->where('resource_id', $post->id)->exists())->toBeFalse();
});

it('does not duplicate feed entries when the same post is announced twice', function () {
    $post = publishAsAuthor($this, 'Una sola vez');
    $indexId = DiscoveryIndex::query()->where('resource_id', $post->id)->value('id');

    app(PublishPublication::class)->handle($this->author, $post->fresh());

    expect(FeedEntry::query()->where('item_id', $post->id)->count())->toBe(3)
        ->and(DiscoveryIndex::query()->where('resource_id', $post->id)->value('id'))->toBe($indexId);
});
