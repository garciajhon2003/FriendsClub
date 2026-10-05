<?php

use Database\Seeders\DemoDataSeeder;
use Liberu\SocialNetwork\Hobbies\Livewire\Components\Explore;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

beforeEach(fn () => seed(DemoDataSeeder::class));

it('shows the explore page to guests, calling communities clubs', function () {
    get('/explorar')
        ->assertOk()
        ->assertSee('Explore clubs')
        ->assertSee('Amantes de la Repostería')
        ->assertSee('Fotografía Urbana');
});

it('filters clubs when the user picks a hobby', function () {
    Livewire::test(Explore::class)
        ->set('hobby', 'reposteria')
        ->assertSee('Amantes de la Repostería')
        ->assertDontSee('Fotografía Urbana');
});

it('shows nothing for a hobby that does not exist', function () {
    Livewire::test(Explore::class)
        ->set('hobby', 'no-existe')
        ->assertSee('No clubs match your filters.')
        ->assertDontSee('Amantes de la Repostería');
});

it('shows nearby clubs with their distance when a city is picked', function () {
    Livewire::test(Explore::class)
        ->set('city', 'valencia')
        ->assertSee('Bienestar en Equilibrio')
        ->assertSee('km')
        ->assertDontSee('Amantes de la Repostería');
});

it('ignores an invalid modality in the URL instead of failing', function () {
    Livewire::test(Explore::class)
        ->set('modality', 'hackeo')
        ->assertOk()
        ->assertSee('Amantes de la Repostería');
});

it('hides the city selector when the composition lists no cities', function () {
    config()->set('social-network-hobbies-livewire.cities', []);

    Livewire::test(Explore::class)
        ->assertDontSee('All locations')
        ->assertSee('Amantes de la Repostería');
});

it('translates the page to Spanish', function () {
    app()->setLocale('es');

    Livewire::test(Explore::class)
        ->assertSee('Explorar clubs')
        ->assertSee('Todas las ubicaciones')
        ->assertSee('Presencial');
});
