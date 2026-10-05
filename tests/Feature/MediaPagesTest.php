<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Media\Filament\Pages\Albums;
use Liberu\SocialNetwork\Media\Filament\Pages\Assets;
use Liberu\SocialNetwork\Media\Models\Album;
use Liberu\SocialNetwork\Media\Models\MediaAsset;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');

    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => $this->user->getKey(), 'handle' => 'tester']);

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function storedAsset(Profile $owner, string $path, array $attributes = []): MediaAsset
{
    Storage::disk('public')->put($path, 'contenido');

    return MediaAsset::query()->create([
        'id' => (string) Str::uuid(),
        'owner_profile_id' => $owner->id,
        'type' => 'image',
        'state' => 'ready',
        'disk' => 'public',
        'path' => $path,
        ...$attributes,
    ]);
}

function albumOf(Profile $owner, string $name, string $privacy = 'private'): Album
{
    return Album::query()->create(['id' => (string) Str::uuid(), 'owner_profile_id' => $owner->id, 'name' => $name, 'privacy' => $privacy]);
}

it('renders the media and album pages', function () {
    $this->get(route('filament.app.pages.assets'))->assertOk()->assertSee('My media');
    $this->get(route('filament.app.pages.albums'))->assertOk()->assertSee('My albums');
});

it('lists only my media', function () {
    $mine = storedAsset($this->profile, 'media/mine.jpg');
    $other = Profile::query()->create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->getKey(), 'handle' => 'other']);
    $theirs = storedAsset($other, 'media/theirs.jpg');

    Livewire::test(Assets::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('uploads files into an album and marks them ready', function () {
    $album = albumOf($this->profile, 'Vacaciones');

    Livewire::test(Assets::class)
        ->callAction('upload', [
            'files' => [UploadedFile::fake()->image('playa.jpg'), UploadedFile::fake()->create('ruta.pdf', 20, 'application/pdf')],
            'album_id' => $album->id,
            'alt_text' => 'Verano 2026',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('2 files uploaded');

    $assets = MediaAsset::query()->where('owner_profile_id', $this->profile->id)->get();

    expect($assets)->toHaveCount(2)
        ->and($assets->pluck('type')->sort()->values()->all())->toBe(['file', 'image'])
        ->and($assets->pluck('state')->unique()->all())->toBe(['ready'])
        ->and($assets->pluck('album_id')->unique()->all())->toBe([$album->id])
        ->and($assets->first()->path)->toStartWith('media/'.$this->profile->id.'/');

    $assets->each(fn (MediaAsset $asset) => Storage::disk('public')->assertExists($asset->path));
});

it('edits the details of a file', function () {
    $asset = storedAsset($this->profile, 'media/foto.jpg');
    $album = albumOf($this->profile, 'Club');

    Livewire::test(Assets::class)
        ->callTableAction('edit', $asset, ['album_id' => $album->id, 'alt_text' => 'Grupo en la cima', 'captions' => 'Domingo'])
        ->assertHasNoTableActionErrors();

    expect($asset->fresh())->album_id->toBe($album->id)->alt_text->toBe('Grupo en la cima')->captions->toBe('Domingo');
});

it('deletes a file and its stored copy', function () {
    $asset = storedAsset($this->profile, 'media/borrar.jpg');

    Livewire::test(Assets::class)->callTableAction('delete', $asset);

    expect(MediaAsset::query()->find($asset->id))->toBeNull();
    Storage::disk('public')->assertMissing('media/borrar.jpg');
});

it('reports a pending file whose stored copy is missing', function () {
    $asset = storedAsset($this->profile, 'media/perdido.jpg', ['state' => 'pending']);
    Storage::disk('public')->delete('media/perdido.jpg');

    Livewire::test(Assets::class)
        ->callTableAction('markReady', $asset)
        ->assertNotified('The media file is unavailable.');

    expect($asset->fresh()->state)->toBe('pending');
});

it('creates, edits and deletes albums, keeping their media', function () {
    Livewire::test(Albums::class)
        ->callAction('create', ['name' => 'Excursiones', 'description' => 'Rutas del club', 'privacy' => 'friends_only'])
        ->assertHasNoActionErrors();

    $album = Album::query()->where('name', 'Excursiones')->firstOrFail();
    $asset = storedAsset($this->profile, 'media/en-album.jpg', ['album_id' => $album->id]);

    expect($album->privacy)->toBe('friends_only');

    Livewire::test(Albums::class)
        ->assertSee('Friends only')
        ->callTableAction('edit', $album, ['name' => 'Excursiones 2026', 'privacy' => 'public'])
        ->callTableAction('delete', $album);

    expect(Album::query()->find($album->id))->toBeNull()
        ->and($asset->fresh()->album_id)->toBeNull();
});

it('filters media by album from the albums page link', function () {
    $album = albumOf($this->profile, 'Fotos');
    $inAlbum = storedAsset($this->profile, 'media/dentro.jpg', ['album_id' => $album->id]);
    $outside = storedAsset($this->profile, 'media/fuera.jpg');

    Livewire::test(Assets::class)
        ->filterTable('album_id', $album->id)
        ->assertCanSeeTableRecords([$inAlbum])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('opens the media page already filtered by the album in the link', function () {
    $album = albumOf($this->profile, 'Montaña');
    $inAlbum = storedAsset($this->profile, 'media/cumbre.jpg', ['album_id' => $album->id, 'alt_text' => 'Foto de la cumbre']);
    storedAsset($this->profile, 'media/otra.jpg', ['alt_text' => 'Foto sin álbum']);

    Livewire::withQueryParams(['filters' => ['album_id' => ['value' => $album->id]]])
        ->test(Assets::class)
        ->assertSee('Foto de la cumbre')
        ->assertDontSee('Foto sin álbum');
});
