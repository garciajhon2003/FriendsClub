<?php

use App\Livewire\ExploreClubs;
use Database\Seeders\DemoDataSeeder;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

beforeEach(fn() => seed(DemoDataSeeder::class));

it('shows the explore page to guests', function () {
    get('/explorar')
        ->assertOk()
        ->assertSee('Amantes de la Repostería')
        ->assertSee('Fotografía Urbana');
});

it('filters clubs when the user picks a hobby', function () {
    Livewire::test(ExploreClubs::class)
        ->set('hobby', 'reposteria')
        ->assertSee('Amantes de la Repostería')
        ->assertDontSee('Fotografía Urbana');
});

it('shows nearby clubs with their distance when a city is picked', function () {
    Livewire::test(ExploreClubs::class)
        ->set('city', 'valencia')
        ->assertSee('Bienestar en Equilibrio')
        ->assertSee('km')
        ->assertDontSee('Amantes de la Repostería');
});

it('ignores an invalid modality in the URL instead of failing', function () {
    Livewire::test(ExploreClubs::class)
        ->set('modality', 'hackeo')
        ->assertOk()
        ->assertSee('Amantes de la Repostería');
});

it('translates the page to Spanish', function () {
    app()->setLocale('es');

    Livewire::test(ExploreClubs::class)
        ->assertSee('Todas las ubicaciones')
        ->assertSee('Presencial');
});
