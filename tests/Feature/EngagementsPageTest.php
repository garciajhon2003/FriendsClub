<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Engagement\Filament\Pages\Engagements;
use Liberu\SocialNetwork\Engagement\Models\Engagement;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->user->getKey(),
        'handle' => 'tester',
    ]);
    $this->post = Publication::query()->create([
        'id' => (string) Str::uuid(),
        'author_profile_id' => $this->profile->id,
        'kind' => 'post',
        'state' => 'published',
        'audience' => 'public',
        'title' => 'Ruta por Collserola',
        'body' => 'Fotos del domingo',
    ]);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function engagementOf(Profile $actor, Publication $target, string $kind, ?string $reaction = null, ?string $body = null): Engagement
{
    return Engagement::query()->create([
        'id' => (string) Str::uuid(),
        'actor_profile_id' => $actor->id,
        'target_type' => $target->getMorphClass(),
        'target_id' => $target->id,
        'kind' => $kind,
        'reaction_type' => $reaction,
        'body' => $body,
    ]);
}

it('renders the activity page', function () {
    $this->get(route('filament.app.pages.engagements'))->assertOk()->assertSee('Your activity');
});

it('lists my engagements with the content they point to, and nobody else\'s', function () {
    $mine = engagementOf($this->profile, $this->post, 'comment', body: '¡Me apunto!');
    $other = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->getKey(), 'handle' => 'other']);
    $theirs = engagementOf($other, $this->post, 'reaction', 'like');

    Livewire::test(Engagements::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSee(['Ruta por Collserola', '¡Me apunto!']);
});

it('labels reactions and bookmarks', function () {
    engagementOf($this->profile, $this->post, 'reaction', 'love');
    engagementOf($this->profile, $this->post, 'bookmark');

    Livewire::test(Engagements::class)->assertSee(['❤️ Love', 'Saved']);
});

it('edits a comment through the module action', function () {
    $comment = engagementOf($this->profile, $this->post, 'comment', body: 'Primera versión');

    Livewire::test(Engagements::class)
        ->assertTableActionVisible('editComment', $comment)
        ->callTableAction('editComment', $comment, ['body' => 'Versión corregida'])
        ->assertHasNoTableActionErrors();

    expect($comment->fresh()->body)->toBe('Versión corregida');
});

it('only offers editing on comments', function () {
    $reaction = engagementOf($this->profile, $this->post, 'reaction', 'like');

    Livewire::test(Engagements::class)->assertTableActionHidden('editComment', $reaction);
});

it('undoes an engagement', function () {
    $bookmark = engagementOf($this->profile, $this->post, 'bookmark');

    Livewire::test(Engagements::class)->callTableAction('undo', $bookmark);

    expect(Engagement::query()->find($bookmark->id))->toBeNull();
});

it('marks content that no longer exists', function () {
    engagementOf($this->profile, $this->post, 'reaction', 'like');
    $this->post->delete();

    Livewire::test(Engagements::class)->assertSee('no longer available');
});
