<?php

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages\CreateHobby;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages\ListHobbies;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;
use Livewire\Livewire;

use function Pest\Laravel\seed;

beforeEach(function () {
    seed(DemoDataSeeder::class);

    $team = Team::factory()->create();
    $admin = User::factory()->create(['current_team_id' => $team->id]);

    Gate::before(fn () => true);
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($team);
});

it('is not scoped to the team tenant', function () {
    expect(HobbyResource::isScopedToTenant())->toBeFalse();
});

it('lists the hobby catalog', function () {
    Livewire::test(ListHobbies::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Hobby::all());
});

it('creates a hobby and generates its slug', function () {
    Livewire::test(CreateHobby::class)
        ->fillForm(['name' => 'Jardinería'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Hobby::where('slug', 'jardineria')->exists())->toBeTrue();
});

it('knows which hobbies are in use', function () {
    $reposteria = Hobby::where('slug', 'reposteria')->first();
    $realities = Hobby::where('slug', 'realities')->first();

    expect($reposteria->isInUse())->toBeTrue()
        ->and($realities->isInUse())->toBeFalse();
});
