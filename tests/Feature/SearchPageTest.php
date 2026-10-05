<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Discovery\Filament\Pages\Search;
use Liberu\SocialNetwork\Discovery\Models\DiscoveryIndex;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $this->user->getKey(), 'handle' => 'tester']);
    $this->other = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->getKey(), 'handle' => 'other']);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function indexed(Profile $owner, string $body, array $attributes = []): DiscoveryIndex
{
    return DiscoveryIndex::query()->create([
        'id' => (string) Str::uuid(),
        'resource_type' => (new Publication())->getMorphClass(),
        'resource_id' => (string) Str::uuid(),
        'owner_profile_id' => $owner->id,
        'visibility' => 'public',
        'body' => $body,
        'terms' => [],
        'engagement_score' => 0,
        'published_at' => now(),
        ...$attributes,
    ]);
}

it('renders the search page', function () {
    $this->get(route('filament.app.pages.search'))->assertOk()->assertSee('Discover');
});

it('finds public content and my own private content, but not other people private content', function () {
    $public = indexed($this->other, 'Ruta de senderismo por Collserola');
    $mine = indexed($this->profile, 'Mi senderismo privado', ['visibility' => 'private']);
    $hidden = indexed($this->other, 'Senderismo secreto de otro', ['visibility' => 'private']);
    $unrelated = indexed($this->other, 'Receta de tarta de limón');

    Livewire::test(Search::class)
        ->searchTable('senderismo')
        ->assertCanSeeTableRecords([$public, $mine])
        ->assertCanNotSeeTableRecords([$hidden, $unrelated]);
});

it('orders results by popularity', function () {
    indexed($this->other, 'Club de fotografía poco popular', ['engagement_score' => 1]);
    indexed($this->other, 'Club de fotografía muy popular', ['engagement_score' => 50]);

    Livewire::test(Search::class)
        ->searchTable('fotografía')
        ->assertSeeInOrder(['muy popular', 'poco popular']);
});

it('shows trending terms and searches one when clicked', function () {
    indexed($this->other, 'Senderismo al atardecer', ['terms' => ['senderismo', 'atardecer'], 'engagement_score' => 9]);
    indexed($this->other, 'Senderismo en grupo', ['terms' => ['senderismo', 'grupo'], 'engagement_score' => 5]);
    indexed($this->other, 'Tarta de limón', ['terms' => ['tarta'], 'engagement_score' => 1]);

    Livewire::test(Search::class)
        ->assertSee(['#senderismo', '#tarta'])
        ->call('searchFor', 'tarta')
        ->assertSet('tableSearch', 'tarta')
        ->assertSee('Tarta de limón')
        ->assertDontSee('Senderismo en grupo');
});

it('reads the search from the URL', function () {
    indexed($this->other, 'Club de lectura de octubre');
    indexed($this->other, 'Partido de pádel');

    Livewire::withQueryParams(['q' => 'lectura'])
        ->test(Search::class)
        ->assertSee('Club de lectura de octubre')
        ->assertDontSee('Partido de pádel');
});
